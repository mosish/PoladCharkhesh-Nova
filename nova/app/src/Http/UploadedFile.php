<?php

declare(strict_types=1);

namespace Nova\Http;

/**
 * One uploaded file, read when the request is built: only a file PHP itself
 * received over HTTP (`is_uploaded_file`) is read, never a path a client
 * names. `$error` is a PHP UPLOAD_ERR_* code; the content is empty unless it
 * is UPLOAD_ERR_OK. Tests build these directly.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $name,
        public string $bytes,
        public int $error = UPLOAD_ERR_OK,
    ) {}

    public function ok(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->bytes !== '';
    }

    /**
     * @param array<array-key, mixed> $files the $_FILES array
     *
     * @return array<string, self>
     */
    public static function fromGlobals(array $files): array
    {
        $out = [];
        foreach ($files as $field => $info) {
            if (!\is_array($info) || !\is_string($info['name'] ?? null) || !\is_string($info['tmp_name'] ?? null) || !\is_int($info['error'] ?? null)) {
                continue; // multi-file fields are not used
            }
            $bytes = '';
            if ($info['error'] === UPLOAD_ERR_OK && is_uploaded_file($info['tmp_name'])) {
                $bytes = (string) file_get_contents($info['tmp_name']);
            }
            $out[(string) $field] = new self($info['name'], $bytes, $info['error'] === UPLOAD_ERR_OK && $bytes === '' ? UPLOAD_ERR_NO_FILE : $info['error']);
        }

        return $out;
    }
}
