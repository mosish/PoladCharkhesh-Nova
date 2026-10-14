<?php

declare(strict_types=1);

namespace Nova\Media;

use Nova\Core\Config;

/**
 * Upload safety caps (05 §12): limits against abuse and decompression
 * bombs, configurable under `media.*`. They are not performance budgets (D14).
 */
final readonly class MediaLimits
{
    public function __construct(
        public int $maxImageBytes = 20_000_000,
        public int $maxPixels = 50_000_000,
        public int $maxPdfBytes = 30_000_000,
        public int $maxSvgBytes = 512_000,
        public int $maxSvgNodes = 5_000,
        public int $maxSvgDepth = 32,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $media = $config->get('media');
        $media = \is_array($media) ? $media : [];
        $int = static fn(string $key, int $default): int => is_numeric($media[$key] ?? null) && (int) $media[$key] > 0 ? (int) $media[$key] : $default;

        return new self($int('max_image_bytes', 20_000_000), $int('max_pixels', 50_000_000), $int('max_pdf_bytes', 30_000_000), $int('max_svg_bytes', 512_000), $int('max_svg_nodes', 5_000), $int('max_svg_depth', 32));
    }
}
