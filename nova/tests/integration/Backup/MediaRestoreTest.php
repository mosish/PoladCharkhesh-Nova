<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Backup;

use Nova\Backup\BackupError;
use Nova\Backup\BackupManifest;
use Nova\Backup\MediaFiles;
use Nova\Core\Files;
use Nova\Core\Sql;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Media and document restore from manifests and full archives, into temporary folders only. */
final class MediaRestoreTest extends TestCase
{
    private BackupFixture $b;
    private string $errorLog = '';
    private string $set = '';

    protected function setUp(): void
    {
        $this->b = new BackupFixture();
        $this->errorLog = (string) ini_set('error_log', $this->b->tmp . '/error.log');
        $this->b->seed();
        $this->set = $this->b->backups->run('manual')['id'];
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        $this->b->dispose();
    }

    private function target(string $name = 'restored-media'): string
    {
        return $this->b->tmp . '/' . $name;
    }

    /** @return array<string, string> relative path => sha256, for every file under a folder */
    private static function tree(string $dir): array
    {
        $out = [];
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                \assert($f instanceof \SplFileInfo);
                $out[substr($f->getPathname(), \strlen($dir) + 1)] = (string) hash_file('sha256', $f->getPathname());
            }
        }
        ksort($out);

        return $out;
    }

    public function testTheManifestRestoresEveryFileExactlyAndRepeatingItChangesNothing(): void
    {
        $r = $this->b->restores->restoreMedia($this->set, $this->target());
        self::assertSame(['restored' => 5, 'unchanged' => 0, 'missing_at_backup' => 0], $r);
        self::assertSame(self::tree($this->b->mediaDir), self::tree($this->target()), 'the restored folder equals the source folder, byte for byte');

        $again = $this->b->restores->restoreMedia($this->set, $this->target());
        self::assertSame(['restored' => 0, 'unchanged' => 5, 'missing_at_backup' => 0], $again);
        self::assertSame(self::tree($this->b->mediaDir), self::tree($this->target()));
        self::assertContains('restore.media', $this->b->auditActions());
    }

    public function testWrongTargetsAreRefusedAndNothingIsWritten(): void
    {
        $foreign = $this->target('foreign');
        mkdir($foreign, 0775, true);
        file_put_contents($foreign . '/notes.txt', 'not media');
        $withLink = $this->target('linked');
        mkdir($withLink, 0775, true);
        symlink('/etc', $withLink . '/2026');
        $aFile = $this->target('a-file');
        file_put_contents($aFile, 'x');

        $cases = [
            'a folder that holds other things' => $foreign,
            'a folder with a symlink' => $withLink,
            'a file' => $aFile,
            'the backup folder itself' => $this->b->paths->root,
            'inside the backup folder' => $this->b->paths->store() . '/2026',
            'a parent of the backup folder' => $this->b->tmp . '/storage',
            'the filesystem root' => '/',
            'a top-level folder' => '/media',
        ];
        foreach ($cases as $label => $dir) {
            try {
                $this->b->restores->restoreMedia($this->set, $dir);
                self::fail("accepted {$label}");
            } catch (BackupError $e) {
                self::assertSame('wrong_target', $e->reason, $label);
            }
        }
        self::assertSame(['notes.txt'], array_map('basename', glob($foreign . '/*') ?: []));
        self::assertSame(0, \count(glob('/etc/*.png') ?: []));
        self::assertContains('restore.refused', $this->b->auditActions());
        self::assertNotContains('restore.media', $this->b->auditActions());
    }

    public function testAFolderThatAlreadyHoldsOnlyMediaIsAcceptedAndHtaccessIsIgnored(): void
    {
        $dir = $this->target();
        mkdir($dir . '/2026', 0775, true);
        file_put_contents($dir . '/.htaccess', "Options -Indexes\n");
        self::assertSame(5, $this->b->restores->restoreMedia($this->set, $dir)['restored']);
    }

    public function testPathsOutsideTheOneAllowedShapeAreRejectedWhateverTheManifestSays(): void
    {
        $hash = str_repeat('a', 64);
        foreach (['../../x.png', '/etc/passwd', '2026/../../x.png', "2026/{$hash}.php", "2026/{$hash}.png/../x", 'a/b.png', "2026\\{$hash}.png", "2026/{$hash}.png\0.php", ''] as $bad) {
            try {
                MediaFiles::restore([['path' => $bad, 'sha256' => $hash, 'bytes' => 1]], $this->b->paths->store(), $this->target('t-' . bin2hex(random_bytes(2))), $this->b->paths->root);
                self::fail('accepted path ' . json_encode($bad));
            } catch (BackupError $e) {
                self::assertSame('traversal', $e->reason, (string) json_encode($bad));
            }
        }
        // And a manifest carrying such a path is refused when it is read.
        $this->b->rewriteManifest($this->set, static function (array $m): array {
            /** @var array{media: array{files: list<array<string, mixed>>}} $m */
            $m['media']['files'][0]['path'] = '../../escape.png';

            return $m;
        });
        try {
            $this->b->restores->restoreMedia($this->set, $this->target());
            self::fail('accepted a manifest with a traversal path');
        } catch (BackupError $e) {
            self::assertSame('corrupt', $e->reason);
        }
        self::assertFileDoesNotExist($this->b->tmp . '/escape.png');
        self::assertFileDoesNotExist($this->target());
    }

    public function testDamagedOrMissingBackupFilesAreRejectedBeforeAnythingIsWritten(): void
    {
        $m = BackupManifest::read($this->b->paths, $this->set);
        $victim = $this->b->paths->store() . '/' . $m['media']['files'][2]['path'];
        $original = (string) file_get_contents($victim);
        file_put_contents($victim, $original . 'x');
        try {
            $this->b->restores->restoreMedia($this->set, $this->target());
            self::fail('restored a damaged file');
        } catch (BackupError $e) {
            self::assertSame('corrupt', $e->reason);
        }
        self::assertFileDoesNotExist($this->target(), 'not even the folder was created');
        self::assertNotSame([], $this->b->backups->verify($this->set));

        unlink($victim);
        try {
            $this->b->restores->restoreMedia($this->set, $this->target());
            self::fail('restored with a file missing');
        } catch (BackupError $e) {
            self::assertSame('incomplete', $e->reason);
        }
        self::assertFileDoesNotExist($this->target());
    }

    public function testAConflictingFileInTheTargetIsRefusedBeforeAnyWrite(): void
    {
        $m = BackupManifest::read($this->b->paths, $this->set);
        $dir = $this->target();
        $clash = $dir . '/' . $m['media']['files'][4]['path'];
        mkdir(\dirname($clash), 0775, true);
        file_put_contents($clash, 'something else under a hash name');
        try {
            $this->b->restores->restoreMedia($this->set, $dir);
            self::fail('overwrote a different file');
        } catch (BackupError $e) {
            self::assertSame('conflict', $e->reason);
        }
        self::assertSame(['something else under a hash name'], array_map(static fn(string $f): string => (string) file_get_contents($f), glob($dir . '/*/*') ?: []), 'and the other files were not written either');
    }

    public function testAnInterruptedMediaRestoreCompletesOnTheNextRunAndLeavesNoTemporaryFiles(): void
    {
        $m = BackupManifest::read($this->b->paths, $this->set);
        $dir = $this->target();
        // As if the process died after two files and part-way through a third.
        foreach (\array_slice($m['media']['files'], 0, 2) as $f) {
            @mkdir(\dirname($dir . '/' . $f['path']), 0775, true);
            copy($this->b->paths->store() . '/' . $f['path'], $dir . '/' . $f['path']);
        }
        $third = $m['media']['files'][2]['path'];
        file_put_contents($dir . '/' . $third . '.tmp-deadbeef', 'half a file');

        $r = $this->b->restores->restoreMedia($this->set, $dir);
        self::assertSame(['restored' => 3, 'unchanged' => 2, 'missing_at_backup' => 0], $r);
        self::assertSame([], glob($dir . '/*/*.tmp-*') ?: []);
        self::assertSame(self::tree($this->b->mediaDir), self::tree($dir));
    }

    public function testFilesMissingFromDiskAtBackupTimeAreReportedNotHidden(): void
    {
        $m = BackupManifest::read($this->b->paths, $this->set);
        unlink($this->b->mediaDir . '/' . $m['media']['files'][0]['path']);
        $this->b->clock->time += 60;
        $second = $this->b->backups->run('manual');
        $m2 = BackupManifest::read($this->b->paths, $second['id']);
        self::assertCount(1, $m2['media']['missing']);
        self::assertNotSame([], $m2['warnings']);
        self::assertCount(4, $m2['media']['files']);
        $r = $this->b->restores->restoreMedia($second['id'], $this->target());
        self::assertSame([4, 1], [$r['restored'], $r['missing_at_backup']]);
    }

    public function testAFullArchiveRestoresTheSameFilesAndRejectsAHostileOne(): void
    {
        $zip = $this->b->backups->archive($this->set);
        self::assertFileExists($zip);
        self::assertSame('0600', substr(\sprintf('%o', fileperms($zip)), -4));
        self::assertSame(['restored' => 5, 'unchanged' => 0, 'missing_at_backup' => 0], $this->b->restores->restoreMedia($this->set, $this->target(), $zip));
        self::assertSame(self::tree($this->b->mediaDir), self::tree($this->target()));
        self::assertContains('backup.archive', $this->b->auditActions());

        // An archive whose manifest names a path outside the allowed shape.
        $hostile = $this->b->tmp . '/hostile.zip';
        $z = new ZipArchive();
        $z->open($hostile, ZipArchive::CREATE);
        $z->addFromString('media-manifest.json', json_encode(['format' => 'nova-media/1', 'files' => [['path' => '../../evil.png', 'sha256' => str_repeat('b', 64), 'bytes' => 1]]], JSON_THROW_ON_ERROR));
        $z->addFromString('../../evil.png', 'EVIL');
        $z->close();
        try {
            $this->b->restores->restoreMedia($this->set, $this->target('from-hostile'), $hostile);
            self::fail('accepted a hostile archive');
        } catch (BackupError $e) {
            self::assertSame('traversal', $e->reason);
        }
        self::assertFileDoesNotExist($this->b->tmp . '/evil.png');
        self::assertFileDoesNotExist(\dirname($this->b->tmp) . '/evil.png');

        // An archive whose file content does not match its manifest.
        $m = BackupManifest::read($this->b->paths, $this->set);
        $f = $m['media']['files'][0];
        $tampered = $this->b->tmp . '/tampered.zip';
        $z = new ZipArchive();
        $z->open($tampered, ZipArchive::CREATE);
        $z->addFromString('media-manifest.json', json_encode(['format' => 'nova-media/1', 'files' => [$f]], JSON_THROW_ON_ERROR));
        $z->addFromString($f['path'], 'not the real content');
        $z->close();
        try {
            $this->b->restores->restoreMedia($this->set, $this->target('from-tampered'), $tampered);
            self::fail('accepted a tampered archive');
        } catch (BackupError $e) {
            self::assertSame('corrupt', $e->reason);
        }
        self::assertFileDoesNotExist($this->target('from-tampered'));
        self::assertSame([], glob($this->b->paths->tmp() . '/stage-*') ?: [], 'staging is cleaned up');
    }

    public function testTheExpectedFileListComesFromTheDatabaseIncludingTrashedItems(): void
    {
        $id = (int) Sql::scalar($this->b->f->pdo, 'SELECT id FROM media LIMIT 1');
        $this->b->media->trash($this->b->f->editor, $id);
        $expected = MediaFiles::expected($this->b->f->pdo);
        self::assertCount(5, $expected, 'a trashed item can be restored from the trash, so its files are backed up');
        Files::removeTree($this->b->paths->root);
        self::assertSame($expected, MediaFiles::expected($this->b->f->pdo));
    }
}
