<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Backup;

use Nova\Audit\AuditLog;
use Nova\Backup\BackupConfig;
use Nova\Backup\BackupPaths;
use Nova\Backup\BackupService;
use Nova\Backup\RestoreService;
use Nova\Core\Database;
use Nova\Core\Files;
use Nova\Core\Sql;
use Nova\Media\DocumentService;
use Nova\Media\MediaLimits;
use Nova\Media\MediaService;
use Nova\Media\MediaStorage;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use Nova\Tests\Integration\Publish\TestClock;
use Nova\Tests\Integration\TestDatabase;
use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * A disposable source (the test database, filled with awkward but synthetic data), a temporary media folder, a
 * temporary backup folder and the backup/restore services. Restore targets are separate, operator-created, empty
 * disposable databases named `nova_rt_*` (NOVA_DB_RESTORE_A/B/C); nothing here ever restores over `nova`,
 * `nova_test` or a volume.
 */
final class BackupFixture
{
    public readonly CatalogueFixture $f;
    public readonly TestClock $clock;
    public readonly string $tmp;
    public readonly string $mediaDir;
    public readonly BackupPaths $paths;
    public readonly BackupService $backups;
    public readonly RestoreService $restores;
    public readonly MediaService $media;
    public readonly DocumentService $documents;
    /** @var (\Closure(): PDO)|null */
    public ?\Closure $dumpConnection = null;

    public function __construct(BackupConfig $config = new BackupConfig())
    {
        $this->tmp = sys_get_temp_dir() . '/nova-bk-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/public/media', 0775, true);
        mkdir($this->tmp . '/site/releases', 0775, true);
        $this->mediaDir = $this->tmp . '/public/media';
        $this->f = new CatalogueFixture();
        $this->clock = new TestClock(1_800_000_000);
        $this->paths = new BackupPaths($this->tmp . '/storage/backups');
        $this->backups = new BackupService(
            $this->f->pdo,
            fn(): PDO => $this->dumpConnection !== null ? ($this->dumpConnection)() : Database::connect(TestDatabase::config()),
            $this->paths,
            $config,
            $this->mediaDir,
            $this->clock,
            'test',
            fn(): array => $this->releases(),
        );
        $this->restores = new RestoreService($this->f->pdo, TestDatabase::config(), $this->paths, $this->backups, 99);
        $storage = new MediaStorage($this->tmp . '/public');
        $limits = new MediaLimits();
        $audit = new AuditLog($this->f->pdo);
        $this->media = new MediaService($this->f->pdo, $storage, $limits, $this->clock, $audit, static fn(): array => []);
        $this->documents = new DocumentService($this->f->pdo, $storage, $limits, $this->clock, $audit);
    }

    public function dispose(): void
    {
        Files::removeTree($this->tmp);
    }

    /** @return list<array{id: string, status: string, manifest_sha256: ?string}> */
    public function releases(): array
    {
        $out = [];
        foreach (Sql::rows($this->f->pdo, "SELECT release_id, status FROM publish_record WHERE status IN ('live', 'superseded', 'rolled_back') ORDER BY id") as $r) {
            $file = $this->tmp . '/site/releases/' . $r['release_id'] . '/manifest.json';
            $out[] = ['id' => (string) $r['release_id'], 'status' => (string) $r['status'], 'manifest_sha256' => is_file($file) ? (string) hash_file('sha256', $file) : null];
        }

        return $out;
    }

    /** A retained release on disk and in publish_record, as the Publish Engine leaves them. */
    public function addRelease(string $id, string $status): void
    {
        $dir = $this->tmp . '/site/releases/' . $id;
        mkdir($dir . '/fa', 0775, true);
        file_put_contents($dir . '/fa/index.html', "<html>release {$id}</html>");
        file_put_contents($dir . '/manifest.json', json_encode(['files' => ['fa/index.html' => ['sha256' => hash('sha256', "<html>release {$id}</html>")]]], JSON_THROW_ON_ERROR));
        Sql::run($this->f->pdo, 'INSERT INTO publish_record (release_id, status, strategy, environment, went_live_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))', [$id, $status, 'symlink', 'development']);
    }

    /** Hashes of every file under a directory, to prove it did not change. */
    public function treeHash(string $dir): string
    {
        $parts = [];
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                \assert($file instanceof \SplFileInfo);
                $parts[] = substr($file->getPathname(), \strlen($dir)) . ':' . hash_file('sha256', $file->getPathname());
            }
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * Awkward but synthetic content: quotes, backslashes, newlines, Persian text, emoji, NULLs, a generated column, media and a document.
     *
     * @return array{png: int, pdf: int}
     */
    public function seed(): array
    {
        $f = $this->f;
        $p = $f->products->createProduct($f->owner, $f->family, "QZ O'1");
        $f->products->addVariant($f->owner, $p, 'QZ 1-2RS', $f->source, true);
        $f->products->addSpelling($f->owner, $p, null, 'QZ ONE');
        $q = $f->products->createProduct($f->owner, $f->family, 'QZ 2');
        Sql::run($f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id IN (?, ?)", [$p, $q]);
        Sql::run($f->pdo, 'INSERT INTO inquiry (lang, name, phone, quantity, message, release_id, match_state) VALUES (?, ?, ?, NULL, ?, NULL, ?)', [
            'fa', "O'Brien \"Q\" \\ test", '۰۹۱۲ ۰۰۰ ۰۰۰۰', "line1\nline2\r\n\tPersian: نمونه ✓ 🙂 \\n literal; DROP TABLE x; --\n", 'no_code',
        ]);
        Sql::run($f->pdo, "INSERT INTO setting (setting_key, value_text) VALUES ('company.note', ?)", ["multi\nline 'quoted' \"double\" \\back"]);
        // Short-lived throttle rows keyed by hashed addresses: never part of a backup.
        Sql::run($f->pdo, 'INSERT INTO inquiry_attempt (address_hash, outcome) VALUES (?, ?)', [str_repeat('0', 56) . 'ADDRESS1', 'stored']);
        Sql::run($f->pdo, 'INSERT INTO login_throttle (subject_hash, failures, window_started_at) VALUES (?, 1, UTC_TIMESTAMP())', [str_repeat('0', 56) . 'SUBJECT1']);

        $img = imagecreatetruecolor(2000, 1000);
        imagefilledrectangle($img, 0, 0, 1000, 500, (int) imagecolorallocate($img, 200, 20, 20));
        ob_start();
        imagepng($img);
        $png = $this->media->upload($f->editor, 'wide.png', (string) ob_get_clean())['id'];
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $doc = $this->documents->upload($f->editor, 'ds.pdf', $pdf, 'برگه', 'Datasheet')['id'];
        $this->documents->link($f->editor, $doc, 'QZ 2', 'both');

        return ['png' => $png, 'pdf' => $doc];
    }

    /**
     * A restore target: an empty, operator-created disposable database named nova_rt_*.
     *
     * @param 'A'|'B'|'C' $letter
     */
    public static function target(string $letter): string
    {
        $name = getenv('NOVA_DB_RESTORE_' . $letter);
        if (!\is_string($name) || $name === '') {
            Assert::markTestSkipped("NOVA_DB_RESTORE_{$letter} names no disposable restore target (create an empty nova_rt_* database and pass it).");
        }
        if (preg_match('/^nova_rt_[a-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException("Refusing {$name}: restore test targets must be named nova_rt_*.");
        }
        $pdo = Database::connect(TestDatabase::config()->withName($name));
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Sql::column($pdo, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $t) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $t) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $name;
    }

    public static function connect(string $database): PDO
    {
        return Database::connect(TestDatabase::config()->withName($database));
    }

    /**
     * Rewrites a set's manifest with its checksum sidecar kept consistent, to build damaged-but-self-consistent sets.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     */
    public function rewriteManifest(string $id, \Closure $mutate): void
    {
        $path = $this->paths->manifest($id);
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $json = json_encode($mutate($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($path . '.sha256', hash('sha256', $json) . "\n");
        file_put_contents($path, $json);
    }

    /** Audit rows are stamped with the database clock; tests run on a fake one, so line the failure rows up with it. */
    public function alignFailureTimes(): void
    {
        Sql::run($this->f->pdo, "UPDATE audit_event SET occurred_at = ? WHERE action = 'backup.failed'", [gmdate('Y-m-d H:i:s', $this->clock->now())]);
    }

    /** @return list<string> */
    public function auditActions(): array
    {
        return array_map(strval(...), Sql::column($this->f->pdo, "SELECT action FROM audit_event WHERE entity_type = 'backup' ORDER BY id"));
    }
}
