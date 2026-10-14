<?php

declare(strict_types=1);

namespace Nova\Media;

use finfo;
use GdImage;

/**
 * Raster images (05 §12): validated by content, never by file name; decoded
 * and re-encoded so metadata (EXIF, GPS, embedded profiles and any trailing
 * payload) is gone; orientation is applied to the pixels; responsive WebP
 * copies are produced. The re-encoded bytes are what is stored and hashed.
 */
final class ImageProcessor
{
    public const array TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const array WIDTHS = [480, 960, 1600];

    /**
     * @return array{bytes: string, ext: string, mime: string, width: int, height: int}
     *
     * @throws MediaError
     */
    public static function clean(string $bytes, MediaLimits $limits): array
    {
        if ($bytes === '' || \strlen($bytes) > $limits->maxImageBytes) {
            throw new MediaError('too_large', 'The image is empty or larger than the safety cap.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = \is_string($mime) ? (self::TYPES[$mime] ?? null) : null;
        $mime = (string) $mime;
        if ($ext === null) {
            throw new MediaError('unsupported', 'Only JPEG, PNG and WebP images are accepted (the file content decides, not its name).');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new MediaError('invalid', 'The image could not be read.');
        }
        if ($info[0] * $info[1] > $limits->maxPixels) {
            throw new MediaError('too_large', 'The image has more pixels than the safety cap allows.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new MediaError('invalid', 'The image could not be decoded.');
        }
        if ($mime === 'image/jpeg') {
            $image = self::oriented($image, self::orientation($bytes));
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        $width = imagesx($image);
        $height = imagesy($image);

        return ['bytes' => self::encode($image, $ext), 'ext' => $ext, 'mime' => $mime, 'width' => $width, 'height' => $height];
    }

    /**
     * Responsive WebP copies narrower than the original.
     *
     * @return array<int, string> width => WebP bytes
     */
    public static function derivatives(string $cleanBytes): array
    {
        $image = @imagecreatefromstring($cleanBytes);
        if ($image === false) {
            return [];
        }
        $out = [];
        foreach (self::WIDTHS as $w) {
            if ($w >= imagesx($image)) {
                continue;
            }
            $scaled = imagescale($image, $w, -1, IMG_BICUBIC);
            if ($scaled !== false) {
                $out[$w] = self::encode($scaled, 'webp', 80);
            }
        }

        return $out;
    }

    private static function encode(GdImage $image, string $ext, int $quality = 86): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        match ($ext) {
            'jpg' => imagejpeg($image, null, $quality),
            'png' => imagepng($image, null, 6),
            default => imagewebp($image, null, $quality),
        };

        return (string) ob_get_clean();
    }

    private static function orientation(string $jpeg): int
    {
        if (!\function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($jpeg));

        return \is_array($exif) && \is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;
    }

    private static function oriented(GdImage $image, int $orientation): GdImage
    {
        $result = match ($orientation) {
            2 => self::flip($image, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($image, 180, 0),
            4 => self::flip($image, IMG_FLIP_VERTICAL),
            5 => ($t = imagerotate($image, 270, 0)) === false ? false : self::flip($t, IMG_FLIP_HORIZONTAL),
            6 => imagerotate($image, 270, 0),
            7 => ($t = imagerotate($image, 90, 0)) === false ? false : self::flip($t, IMG_FLIP_HORIZONTAL),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $result === false ? $image : $result;
    }

    private static function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }
}
