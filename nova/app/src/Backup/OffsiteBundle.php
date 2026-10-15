<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Core\Files;
use ZipArchive;

/**
 * An off-site copy of one backup set (05 §18, E-11): a single encrypted file
 * holding the manifest, the database dump and the media files the manifest
 * names, restorable on any host that has Nova. `import` is strict about what
 * it accepts: only the three kinds of entry a set consists of, by exact name
 * shape; anything else (a path with "..", an absolute path, an unknown
 * file) refuses the whole import. An imported set must then pass
 * `BackupService::verify` or it is removed again.
 */
final class OffsiteBundle
{
    public function __construct(private readonly BackupPaths $paths, private readonly BackupService $backups) {}

    public function export(string $id, string $out, #[\SensitiveParameter] string $passphrase, int $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, int $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE): void
    {
        $problems = $this->backups->verify($id);
        if ($problems !== []) {
            throw new BackupError('corrupt', 'Only a verified set is exported: ' . $problems[0]);
        }
        $m = BackupManifest::read($this->paths, $id);
        $this->paths->ensure();
        $tmp = $this->paths->tmp() . '/export-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupError('io', 'Cannot create the export.');
        }
        try {
            $zip->addFromString('bundle.json', json_encode(['format' => 'nova-bundle/1', 'id' => $id], JSON_THROW_ON_ERROR));
            $zip->addFile($this->paths->manifest($id), 'manifest.json');
            $zip->addFile($this->paths->manifest($id) . '.sha256', 'manifest.json.sha256');
            $zip->addFile($this->paths->dump($id), 'db/' . $id . '.sql.gz');
            foreach ($m['media']['files'] as $f) {
                $zip->addFile($this->paths->store() . '/' . $f['path'], 'store/' . $f['path']);
            }
            if (!$zip->close()) {
                throw new BackupError('io', 'Cannot finish the export.');
            }
            OffsiteCrypto::encryptFile($tmp, $out, $passphrase, $ops, $mem);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return string the id of the imported set
     *
     * @throws BackupError
     */
    public function import(string $in, #[\SensitiveParameter] string $passphrase): string
    {
        $this->paths->ensure();
        $tmp = $this->paths->tmp() . '/import-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            OffsiteCrypto::decryptFile($in, $tmp, $passphrase);

            return $this->extract($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function extract(string $zipPath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new BackupError('corrupt', 'The decrypted file is not a backup bundle.');
        }
        try {
            $bundle = json_decode((string) $zip->getFromName('bundle.json'), true);
            $id = \is_array($bundle) && \is_string($bundle['id'] ?? null) ? $bundle['id'] : '';
            if (!\is_array($bundle) || ($bundle['format'] ?? null) !== 'nova-bundle/1' || !BackupPaths::validId($id)) {
                throw new BackupError('corrupt', 'The bundle has no valid header.');
            }
            // Every entry must be one of the known shapes, before anything is written.
            $store = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (\in_array($name, ['bundle.json', 'manifest.json', 'manifest.json.sha256', 'db/' . $id . '.sql.gz'], true)) {
                    continue;
                }
                if (str_starts_with($name, 'store/') && preg_match(MediaFiles::PATH_PATTERN, substr($name, 6)) === 1) {
                    $store[] = substr($name, 6);
                    continue;
                }
                throw new BackupError('traversal', 'The bundle holds an entry that is not part of a backup set.');
            }
            $existing = $this->paths->manifest($id);
            $incoming = (string) $zip->getFromName('manifest.json');
            if (is_file($existing)) {
                if (!hash_equals((string) hash_file('sha256', $existing), hash('sha256', $incoming))) {
                    throw new BackupError('conflict', "A different set {$id} already exists here.");
                }

                return $id;
            }
            foreach ($store as $rel) {
                $dst = $this->paths->store() . '/' . $rel;
                $data = (string) $zip->getFromName('store/' . $rel);
                if (is_file($dst)) {
                    continue;
                }
                if (!is_dir(\dirname($dst)) && !@mkdir(\dirname($dst), 0700, true) && !is_dir(\dirname($dst))) {
                    throw new BackupError('io', 'Cannot create the backup store.');
                }
                Files::writeAtomic($dst, $data);
            }
            // The set's own files go last; the manifest is what makes a set exist.
            Files::writeAtomic($this->paths->dump($id), (string) $zip->getFromName('db/' . $id . '.sql.gz'));
            Files::writeAtomic($existing . '.sha256', (string) $zip->getFromName('manifest.json.sha256'));
            Files::writeAtomic($existing, $incoming);
            @chmod($this->paths->dump($id), 0600);
        } finally {
            $zip->close();
        }
        $problems = $this->backups->verify($id);
        if ($problems !== []) {
            @unlink($this->paths->manifest($id));
            @unlink($this->paths->manifest($id) . '.sha256');
            @unlink($this->paths->dump($id));

            throw new BackupError('corrupt', 'The imported set failed verification: ' . $problems[0]);
        }

        return $id;
    }
}
