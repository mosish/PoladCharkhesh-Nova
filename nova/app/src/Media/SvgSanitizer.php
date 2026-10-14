<?php

declare(strict_types=1);

namespace Nova\Media;

use DOMDocument;
use DOMElement;
use DOMNode;
use enshrined\svgSanitize\Sanitizer;

/**
 * Owner-uploaded SVG ingestion (05 §26, E-7). Untrusted SVG is never stored
 * as uploaded:
 *
 * 1. Checks: not compressed, valid UTF-8, within the size cap, no DOCTYPE,
 *    ENTITY or stylesheet instruction (this refuses external entities and
 *    entity expansion outright).
 * 2. Nova's strict rules rebuild the file from an allow-list into a new
 *    document: only listed SVG elements and attributes survive; `style`
 *    blocks and attributes, scripts, `foreignObject`, `image`, animation,
 *    every `on*` handler, comments and processing instructions never copy
 *    over; `href` may only name an `#id` in the same file; `url()` may only
 *    name an `#id`. Node count and depth are capped.
 * 3. The result is re-serialised from the rebuilt tree and passed through a
 *    maintained sanitizer library (enshrined/svg-sanitize, remote references
 *    removed) as a second layer. The removals of step 2 are reported so the
 *    Owner can fix the source drawing.
 *
 * The file is then shown only through `<img>` and served with a sandbox CSP.
 */
final class SvgSanitizer
{
    private const string NS = 'http://www.w3.org/2000/svg';
    private const string XLINK = 'http://www.w3.org/1999/xlink';

    private const array ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan',
        'use', 'symbol', 'clipPath', 'mask', 'marker', 'linearGradient', 'radialGradient', 'stop', 'pattern',
    ];

    private const array ATTRIBUTES = [
        'id', 'class', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'd', 'points', 'transform',
        'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit',
        'opacity', 'fill-opacity', 'stroke-opacity', 'fill-rule', 'clip-rule', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod', 'patternUnits', 'patternContentUnits', 'patternTransform',
        'preserveAspectRatio', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline', 'letter-spacing',
        'markerWidth', 'markerHeight', 'refX', 'refY', 'orient', 'markerUnits', 'clipPathUnits', 'maskUnits', 'maskContentUnits',
        'dx', 'dy', 'visibility', 'display', 'vector-effect', 'shape-rendering', 'text-rendering', 'color',
    ];

    /** Attributes whose value may be `url(#id)`. */
    private const array URL_ATTRIBUTES = ['fill', 'stroke', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end'];

    /**
     * @throws MediaError
     */
    public static function clean(string $svg, MediaLimits $limits): SvgResult
    {
        if (str_starts_with($svg, "\x1f\x8b")) {
            throw new MediaError('unsupported', 'Compressed SVG (.svgz) is not accepted.');
        }
        if (\strlen($svg) > $limits->maxSvgBytes) {
            throw new MediaError('too_large', 'The SVG is larger than the safety cap.');
        }
        $svg = (string) preg_replace('/^\xEF\xBB\xBF/', '', $svg);
        if (!mb_check_encoding($svg, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $svg) === 1) {
            throw new MediaError('invalid', 'The SVG is not valid UTF-8 text.');
        }
        if (preg_match('/<!\s*(DOCTYPE|ENTITY|ELEMENT|ATTLIST)|<\?xml-stylesheet/i', $svg) === 1) {
            throw new MediaError('invalid', 'The SVG contains a DOCTYPE, entity declaration or stylesheet instruction, which are not accepted. Export it again without them.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $source = new DOMDocument();
            if (!$source->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $source->documentElement === null) {
                throw new MediaError('invalid', 'The file is not well-formed XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $source->documentElement;
        if ($root->localName !== 'svg' || $root->namespaceURI !== self::NS) {
            throw new MediaError('invalid', 'The root element must be <svg> in the SVG namespace.');
        }

        $ids = [];
        foreach ($source->getElementsByTagName('*') as $el) {
            $id = $el->getAttribute('id');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        $out = new DOMDocument('1.0', 'UTF-8');
        $removed = [];
        $nodes = 0;
        $built = self::copy($root, $out, $ids, $limits, $removed, $nodes, 1);
        if ($built === null) {
            throw new MediaError('invalid', 'The SVG has no usable content.');
        }
        $out->appendChild($built);
        [$width, $height] = self::size($built);
        $clean = (string) $out->saveXML($built);

        // Second layer: the maintained library checks the rebuilt file and must find nothing left to change.
        try {
            $library = new Sanitizer();
            $library->removeRemoteReferences(true);
            $library->minify(false);
            $checked = $library->sanitize($clean);
        } catch (\Throwable) {
            $checked = false;
        }
        if (!\is_string($checked) || $checked === '') {
            throw new MediaError('invalid', 'The sanitiser could not verify the rebuilt SVG.');
        }

        return new SvgResult($checked, $width, $height, self::summary($removed));
    }

    /**
     * @param array<string, true> $ids
     * @param array<string, int> $removed
     */
    private static function copy(DOMElement $from, DOMDocument $into, array $ids, MediaLimits $limits, array &$removed, int &$nodes, int $depth): ?DOMElement
    {
        if ($depth > $limits->maxSvgDepth) {
            throw new MediaError('too_large', 'The SVG is nested more deeply than the safety cap allows.');
        }
        if (++$nodes > $limits->maxSvgNodes) {
            throw new MediaError('too_large', 'The SVG has more elements than the safety cap allows.');
        }
        $name = $from->localName;
        if ($from->namespaceURI !== self::NS || !\in_array($name, self::ELEMENTS, true)) {
            $key = 'element <' . (string) $name . '>';
            $removed[$key] = ($removed[$key] ?? 0) + 1;

            return null;
        }
        $to = $name === 'svg' && $depth === 1 ? $into->createElementNS(self::NS, 'svg') : $into->createElementNS(self::NS, (string) $name);
        foreach ($from->attributes ?? [] as $attr) {
            $local = $attr->localName;
            $value = $attr->value;
            $ok = false;
            if ($attr->namespaceURI === null && \in_array($local, self::ATTRIBUTES, true)) {
                $ok = self::valueOk($local, $value, $ids);
            } elseif ($local === 'href' && ($attr->namespaceURI === null || $attr->namespaceURI === self::XLINK) && \in_array($name, ['use', 'linearGradient', 'radialGradient', 'pattern'], true)) {
                $ok = preg_match('/^#([A-Za-z_][\w.\-:]*)$/', $value, $m) === 1 && isset($ids[$m[1]]);
                if ($ok) {
                    $to->setAttribute('href', $value);
                    continue;
                }
            }
            if ($ok) {
                $to->setAttribute($local, $value);
            } else {
                $key = 'attribute ' . $attr->nodeName;
                $removed[$key] = ($removed[$key] ?? 0) + 1;
            }
        }
        foreach ($from->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $copy = self::copy($child, $into, $ids, $limits, $removed, $nodes, $depth + 1);
                if ($copy !== null) {
                    $to->appendChild($copy);
                }
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                if (\in_array($name, ['text', 'tspan', 'title', 'desc'], true)) {
                    $to->appendChild($into->createTextNode((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $child->nodeValue ?? '')));
                }
            } else {
                self::note($removed, $child);
            }
        }

        return $to;
    }

    /** @param array<string, true> $ids */
    private static function valueOk(string $name, string $value, array $ids): bool
    {
        if (\strlen($value) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            return false;
        }
        if (preg_match('/(java|vb)script\s*:|data\s*:|&#|<|expression\s*\(/i', $value) === 1) {
            return false;
        }
        if ($name === 'id' || $name === 'class') {
            return preg_match('/^[A-Za-z_][\w.\-: ]*$/', $value) === 1;
        }
        if (preg_match('/url\s*\(/i', $value) === 1) {
            return \in_array($name, self::URL_ATTRIBUTES, true)
                && preg_match('/^url\(\s*#([A-Za-z_][\w.\-:]*)\s*\)(\s+(none|currentColor|#[0-9a-fA-F]{3,8}|[a-zA-Z]+))?$/', $value, $m) === 1
                && isset($ids[$m[1]]);
        }

        return true;
    }

    /** @param array<string, int> $removed */
    private static function note(array &$removed, DOMNode $node): void
    {
        $key = match ($node->nodeType) {
            XML_COMMENT_NODE => 'comment',
            XML_PI_NODE => 'processing instruction',
            default => 'node',
        };
        $removed[$key] = ($removed[$key] ?? 0) + 1;
    }

    /** @return array{int, int} */
    private static function size(DOMElement $svg): array
    {
        $box = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox')));
        if (\is_array($box) && \count($box) === 4 && is_numeric($box[2]) && is_numeric($box[3]) && (float) $box[2] > 0 && (float) $box[3] > 0) {
            return [max(1, (int) round((float) $box[2])), max(1, (int) round((float) $box[3]))];
        }
        $w = preg_match('/^\s*([0-9.]+)(px)?\s*$/', $svg->getAttribute('width'), $mw) === 1 ? (float) $mw[1] : 0.0;
        $h = preg_match('/^\s*([0-9.]+)(px)?\s*$/', $svg->getAttribute('height'), $mh) === 1 ? (float) $mh[1] : 0.0;
        if ($w <= 0 || $h <= 0) {
            throw new MediaError('invalid', 'The SVG needs a viewBox, or a width and height in pixels, so its size can be recorded.');
        }

        return [max(1, (int) round($w)), max(1, (int) round($h))];
    }

    /**
     * @param array<string, int> $removed
     *
     * @return list<string>
     */
    private static function summary(array $removed): array
    {
        ksort($removed);

        return array_map(static fn(string $what, int $n): string => $n > 1 ? "{$what} ×{$n}" : $what, array_keys($removed), array_values($removed));
    }
}
