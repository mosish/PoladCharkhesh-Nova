<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Core\Files;

/**
 * The manifest of one backup set: what it contains and the checksums that
 * prove it. Written last, so a set without a manifest is an unfinished one
 * and never counts. A sidecar `.sha256` guards the manifest itself against
 * damage (it is not a signature: protection against deliberate tampering
 * comes from keeping the folder private and from the authenticated
 * encryption of off-site copies).
 *
 * @phpstan-type Manifest array{
 *   format: string, id: string, at: int, created_at: string, trigger: string, app_version: string, schema_version: int,
 *   db: array{file: string, sha256: string, bytes: int, statements: int, tables: array<string, array{rows: int, checksum: string, max_id?: int}>},
 *   media: array{files: list<array{path: string, sha256: string, bytes: int}>, missing: list<string>, bytes: int},
 *   releases: list<array{id: string, status: string, manifest_sha256: ?string}>,
 *   excluded: list<string>, warnings: list<string>
 * }
 */
final class BackupManifest
{
    public const string FORMAT = 'nova-backup/1';

    /** @param Manifest $manifest */
    public static function write(BackupPaths $paths, array $manifest): void
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $path = $paths->manifest($manifest['id']);
        Files::writeAtomic($path . '.sha256', hash('sha256', $json) . "\n");
        Files::writeAtomic($path, $json);
        @chmod($path, 0600);
        @chmod($path . '.sha256', 0600);
    }

    /**
     * @return Manifest
     *
     * @throws BackupError not_found, corrupt
     */
    public static function read(BackupPaths $paths, string $id): array
    {
        $path = $paths->manifest($id);
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new BackupError('not_found', "There is no backup set {$id}.");
        }
        $sum = @file_get_contents($path . '.sha256');
        if ($sum === false || !hash_equals(trim($sum), hash('sha256', $json))) {
            throw new BackupError('corrupt', "The manifest of {$id} does not match its checksum.");
        }

        return self::validate(json_decode($json, true), $id);
    }

    /**
     * @return Manifest
     *
     * @throws BackupError corrupt
     */
    public static function validate(mixed $data, string $id): array
    {
        $bad = static fn(string $what): BackupError => new BackupError('corrupt', "The manifest of {$id} is malformed ({$what}).");
        if (!\is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ($data['id'] ?? null) !== $id) {
            throw $bad('format or id');
        }
        foreach (['at', 'schema_version'] as $k) {
            if (!\is_int($data[$k] ?? null)) {
                throw $bad($k);
            }
        }
        foreach (['created_at', 'trigger', 'app_version'] as $k) {
            if (!\is_string($data[$k] ?? null)) {
                throw $bad($k);
            }
        }
        $db = $data['db'] ?? null;
        if (!\is_array($db) || !\is_string($db['file'] ?? null) || $db['file'] !== 'db/' . $id . '.sql.gz'
            || !\is_string($db['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $db['sha256']) !== 1
            || !\is_int($db['bytes'] ?? null) || !\is_int($db['statements'] ?? null) || !\is_array($db['tables'] ?? null)) {
            throw $bad('db');
        }
        foreach ($db['tables'] as $name => $t) {
            if (!\is_string($name) || preg_match('/^[A-Za-z0-9_$]{1,64}$/', $name) !== 1 || !\is_array($t) || !\is_int($t['rows'] ?? null) || !\is_string($t['checksum'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $t['checksum']) !== 1) {
                throw $bad('table ' . (\is_string($name) ? $name : '?'));
            }
        }
        $media = $data['media'] ?? null;
        if (!\is_array($media) || !\is_array($media['files'] ?? null) || !\is_array($media['missing'] ?? null) || !\is_int($media['bytes'] ?? null)) {
            throw $bad('media');
        }
        foreach ($media['files'] as $f) {
            if (!\is_array($f) || !\is_string($f['path'] ?? null) || preg_match(MediaFiles::PATH_PATTERN, $f['path']) !== 1
                || !\is_string($f['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $f['sha256']) !== 1 || !\is_int($f['bytes'] ?? null)) {
                throw $bad('media file');
            }
        }
        foreach (['releases', 'excluded', 'warnings'] as $k) {
            if (!\is_array($data[$k] ?? null)) {
                throw $bad($k);
            }
        }

        /** @var Manifest $data */
        return $data;
    }
}
