<?php

declare(strict_types=1);

namespace Nova\Media;

/**
 * PDF documents (05 §12): validated by content. The check is deliberately
 * conservative and best-effort, since a PDF is a container: it must start
 * with the PDF header, end with an end-of-file marker, stay under the size
 * cap, and not carry active content (JavaScript, launch actions, embedded
 * files, rich media, form submission). Compressed object streams can hide
 * such names from a byte scan, so documents are also always served as
 * downloads with a sandbox CSP and nosniff (public_html/media/.htaccess).
 */
final class PdfInspector
{
    private const array ACTIVE = ['/JavaScript', '/JS', '/Launch', '/OpenAction', '/AA', '/EmbeddedFile', '/RichMedia', '/SubmitForm', '/ImportData', '/GoToR', '/GoToE'];

    /** @throws MediaError */
    public static function check(string $bytes, MediaLimits $limits): void
    {
        if ($bytes === '' || \strlen($bytes) > $limits->maxPdfBytes) {
            throw new MediaError('too_large', 'The PDF is empty or larger than the safety cap.');
        }
        if (!str_starts_with($bytes, '%PDF-') || preg_match('/%%EOF\s*$/', substr($bytes, -1024)) !== 1) {
            throw new MediaError('invalid', 'The file is not a complete PDF (header or end marker missing).');
        }
        $found = [];
        foreach (self::ACTIVE as $name) {
            if (preg_match('/' . preg_quote($name, '/') . '(?![A-Za-z0-9])/', $bytes) === 1) {
                $found[] = $name;
            }
        }
        if ($found !== []) {
            throw new MediaError('invalid', 'The PDF contains active content, which is not accepted: ' . implode(', ', $found) . '. Export it again as a plain PDF.', $found);
        }
    }
}
