<?php

declare(strict_types=1);

namespace Nova\Publish;

use DOMDocument;
use DOMElement;
use DOMXPath;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Structural output checks, with no byte, request, speed or SEO-length budgets. */
final class SeoAudit
{
    /** @return list<string> */
    public static function release(string $directory, BuildConfig $config): array
    {
        $pages = [];
        foreach (['fa', 'en'] as $lang) {
            if (!is_dir("{$directory}/{$lang}")) {
                return ["missing {$lang} release directory"];
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$directory}/{$lang}", RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                \assert($file instanceof SplFileInfo);
                if ($file->getExtension() === 'html') {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($directory) + 1));
                    $pages[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return self::pages($pages, $config);
    }

    /** @param array<string, string> $pages language-prefixed relative filename => rendered HTML
     * @return list<string>
     */
    public static function pages(array $pages, BuildConfig $config): array
    {
        $issues = [];
        $documents = [];
        $titles = [];
        $headings = [];
        foreach ($pages as $file => $html) {
            $doc = new DOMDocument();
            $old = libxml_use_internal_errors(true);
            try {
                $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($old);
            }
            $documents[$file] = $doc;
            $xpath = new DOMXPath($doc);
            $lang = str_starts_with($file, 'fa/') ? 'fa' : 'en';
            $path = substr($file, 2);
            $path = str_ends_with($path, 'index.html') ? substr($path, 0, -10) : $path;
            $is404 = $path === '/404.html';
            foreach (['title' => '//head/title', 'h1' => '//body//h1', 'description' => '//meta[@name="description"]/@content'] as $label => $query) {
                $nodes = $xpath->query($query);
                $text = trim(self::text($xpath, $query));
                if ($nodes === false || $nodes->length !== 1 || $text === '') {
                    $issues[] = "{$file}: requires exactly one nonempty {$label}";
                } elseif ($label === 'title') {
                    if (isset($titles[$lang][$text])) {
                        $issues[] = "{$file}: duplicate title with {$titles[$lang][$text]}";
                    }
                    $titles[$lang][$text] = $file;
                } elseif ($label === 'h1') {
                    if (isset($headings[$lang][$text])) {
                        $issues[] = "{$file}: duplicate main heading with {$headings[$lang][$text]}";
                    }
                    $headings[$lang][$text] = $file;
                }
            }
            $canonical = $xpath->query('//link[@rel="canonical"]/@href');
            if ($is404) {
                if ($canonical !== false && $canonical->length !== 0) {
                    $issues[] = "{$file}: error page must not declare a canonical";
                }
            } elseif ($canonical === false || $canonical->length !== 1 || self::text($xpath, '//link[@rel="canonical"]/@href') !== $config->url($lang, $path)) {
                $issues[] = "{$file}: canonical does not match the page's host and clean path";
            }
            if (!$is404) {
                foreach (['fa' => 'fa', 'en' => 'en', 'x-default' => 'en'] as $alternate => $target) {
                    $node = $xpath->query('//link[@rel="alternate" and @hreflang="' . $alternate . '"]/@href');
                    if ($node === false || $node->length !== 1 || self::text($xpath, '//link[@rel="alternate" and @hreflang="' . $alternate . '"]/@href') !== $config->url($target, $path)) {
                        $issues[] = "{$file}: invalid {$alternate} hreflang";
                    }
                }
            }
            $robotText = self::text($xpath, '//meta[@name="robots"]/@content');
            if ($robotText === '' || ((!$config->indexable || $is404) && !str_contains($robotText, 'noindex'))) {
                $issues[] = "{$file}: missing or unsafe robots directive";
            }
            foreach ($doc->getElementsByTagName('img') as $img) {
                if (!$img->hasAttribute('alt')) {
                    $issues[] = "{$file}: image is missing its alt attribute";
                }
            }
            foreach ($doc->getElementsByTagName('script') as $script) {
                if ($script->getAttribute('type') === 'application/ld+json') {
                    try {
                        $schema = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
                        if (!\is_array($schema) || ($schema['@context'] ?? '') !== 'https://schema.org') {
                            $issues[] = "{$file}: invalid structured-data context";
                        }
                    } catch (JsonException) {
                        $issues[] = "{$file}: malformed structured-data JSON";
                    }
                }
            }
            $visible = clone $doc;
            foreach (['script', 'style'] as $tag) {
                foreach (iterator_to_array($visible->getElementsByTagName($tag)) as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }
            $textNodes = (new DOMXPath($visible))->query('//body//text()');
            $text = '';
            if ($textNodes !== false) {
                foreach ($textNodes as $node) {
                    if ($node instanceof \DOMNode) {
                        $text .= ' ' . $node->textContent;
                    }
                }
            }
            if (preg_match('/lorem ipsum|\bPhase\s+\d+|\bTODO\b|\bTBD\b|\{\{.+?\}\}/iu', $text) === 1) {
                $issues[] = "{$file}: unfinished placeholder text remains";
            }
        }
        foreach ($documents as $file => $doc) {
            foreach ($doc->getElementsByTagName('a') as $link) {
                $issue = self::link($link, $file, $documents, $config);
                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }

        return array_values(array_unique($issues));
    }

    private static function text(DOMXPath $xpath, string $query): string
    {
        $value = $xpath->evaluate('string(' . $query . ')');

        return \is_string($value) ? $value : '';
    }

    /** @param array<string, DOMDocument> $documents */
    private static function link(DOMElement $link, string $file, array $documents, BuildConfig $config): ?string
    {
        if (!$link->hasAttribute('href')) {
            return null;
        }
        $href = $link->getAttribute('href');
        if ($href === '' || $href === '#') {
            return "{$file}: empty placeholder link";
        }
        $lang = str_starts_with($file, 'fa/') ? 'fa' : 'en';
        if (preg_match('#^(?:https?:)?//#i', $href) === 1) {
            $found = false;
            foreach ($config->hosts as $candidate => $origin) {
                if (str_starts_with($href, $origin . '/')) {
                    $lang = $candidate;
                    $href = substr($href, \strlen($origin));
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return null; // External destinations are not crawled during Publish.
            }
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            return preg_match('#^(?:tel|mailto):#i', $href) === 1 ? null : "{$file}: unsafe link scheme";
        }
        $parts = parse_url($href);
        if ($parts === false) {
            return "{$file}: malformed link";
        }
        $path = rawurldecode($parts['path'] ?? '');
        // Uploaded media lives outside the releases (05 §12): content-hash names only, so nothing else can hide behind this path.
        if (preg_match('#^/media/[0-9]{4}/[0-9a-f]{64}(?:-[0-9]+)?\.(?:jpg|png|webp|svg|pdf)$#', $path) === 1) {
            return null;
        }
        if ($path === '') {
            $target = $file;
        } else {
            $target = str_starts_with($path, '/') ? $lang . $path : \dirname($file) . '/' . $path;
            if (str_ends_with($target, '/')) {
                $target .= 'index.html';
            }
        }
        if (!isset($documents[$target])) {
            return "{$file}: broken internal link {$href}";
        }
        if (isset($parts['fragment'])) {
            $fragment = rawurldecode($parts['fragment']);
            foreach ($documents[$target]->getElementsByTagName('*') as $node) {
                if ($node->getAttribute('id') === $fragment) {
                    return null;
                }
            }

            return "{$file}: missing anchor in {$href}";
        }

        return null;
    }
}
