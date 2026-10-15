<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Backup;

use Nova\Backup\BackupConfig;
use Nova\Backup\BackupError;
use Nova\Backup\BackupPaths;
use Nova\Backup\BackupService;
use Nova\Backup\OffsiteBundle;
use Nova\Backup\OffsiteCrypto;
use Nova\Core\Database;
use Nova\Tests\Integration\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Encrypted off-site copies (E-11): authenticated, passphrase-only, and strict about what an import may contain. */
final class OffsiteTest extends TestCase
{
    private const string PASS = 'correct horse battery staple';
    /** Cheap key derivation for tests only; real exports use the moderate defaults. */
    private const int OPS = 2;
    private const int MEM = 8_388_608;

    private BackupFixture $b;
    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->b = new BackupFixture();
        $this->errorLog = (string) ini_set('error_log', $this->b->tmp . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        $this->b->dispose();
    }

    private function file(string $content): string
    {
        $path = $this->b->tmp . '/' . bin2hex(random_bytes(3)) . '.bin';
        file_put_contents($path, $content);

        return $path;
    }

    public function testEncryptionRoundTripsAndNothingReadableIsInTheFile(): void
    {
        $plain = random_bytes(200_000) . str_repeat('NOVA-SECRET-MARKER', 5_000);
        $in = $this->file($plain);
        $enc = $this->b->tmp . '/enc.nova';
        OffsiteCrypto::encryptFile($in, $enc, self::PASS, self::OPS, self::MEM);
        self::assertStringNotContainsString('NOVA-SECRET-MARKER', (string) file_get_contents($enc));
        self::assertStringNotContainsString(self::PASS, (string) file_get_contents($enc));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($enc)), -4));
        $out = $this->b->tmp . '/dec.bin';
        OffsiteCrypto::decryptFile($enc, $out, self::PASS);
        self::assertSame(hash('sha256', $plain), hash_file('sha256', $out));

        // An empty file and an exact multiple of the chunk size.
        foreach ([0, 65_536, 131_072] as $size) {
            $p = $this->file(str_repeat('z', $size));
            OffsiteCrypto::encryptFile($p, $p . '.enc', self::PASS, self::OPS, self::MEM);
            OffsiteCrypto::decryptFile($p . '.enc', $p . '.dec', self::PASS);
            self::assertSame(hash('sha256', str_repeat('z', $size)), hash_file('sha256', $p . '.dec'), "size {$size}");
        }
    }

    public function testAWrongPassphraseADamagedFileAndATruncatedFileAreAllRefused(): void
    {
        $in = $this->file(random_bytes(150_000));
        $enc = $this->b->tmp . '/enc.nova';
        OffsiteCrypto::encryptFile($in, $enc, self::PASS, self::OPS, self::MEM);
        $good = (string) file_get_contents($enc);
        $refuse = function (string $bytes, string $pass, string $reason, string $why) use ($enc): void {
            file_put_contents($enc . '.t', $bytes);
            try {
                OffsiteCrypto::decryptFile($enc . '.t', $enc . '.out', $pass);
                self::fail("accepted: {$why}");
            } catch (BackupError $e) {
                self::assertSame($reason, $e->reason, $why);
            }
            self::assertFileDoesNotExist($enc . '.out', "{$why}: no output is left");
            self::assertFileDoesNotExist($enc . '.out.part');
        };
        $refuse($good, 'a different passphrase!', 'passphrase', 'wrong passphrase');
        $flip = $good;
        $flip[\strlen($good) - 100] = \chr(\ord($flip[\strlen($good) - 100]) ^ 1);
        $refuse($flip, self::PASS, 'corrupt', 'a flipped byte late in the file');
        $refuse(substr($good, 0, \strlen($good) - 70_000), self::PASS, 'incomplete', 'truncated at a chunk boundary or inside one');
        $refuse(substr($good, 0, 100), self::PASS, 'incomplete', 'cut inside the first chunk');
        $refuse($good . 'extra', self::PASS, 'corrupt', 'data after the end');
        $refuse('NOT A NOVA FILE AT ALL........................................', self::PASS, 'corrupt', 'not an encrypted backup');
        // Unsafe key-derivation settings in a crafted header must not be honoured.
        $evil = substr_replace($good, pack('NN', 99, 8), \strlen(OffsiteCrypto::MAGIC), 8);
        $refuse($evil, self::PASS, 'corrupt', 'unsafe cost parameters');
        $huge = substr_replace($good, pack('NN', 3, 4_000_000), \strlen(OffsiteCrypto::MAGIC), 8);
        $refuse($huge, self::PASS, 'corrupt', 'a memory bomb');
        // A short passphrase is refused when encrypting.
        try {
            OffsiteCrypto::encryptFile($in, $enc . '.short', 'short', self::OPS, self::MEM);
            self::fail('accepted a short passphrase');
        } catch (BackupError $e) {
            self::assertSame('passphrase', $e->reason);
        }
    }

    public function testASetExportsToOneEncryptedFileAndImportsOnAnotherHost(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $enc = $this->b->tmp . '/offsite.nova';
        (new OffsiteBundle($this->b->paths, $this->b->backups))->export($run['id'], $enc, self::PASS, self::OPS, self::MEM);
        $raw = (string) file_get_contents($enc);
        self::assertStringNotContainsString('CREATE TABLE', $raw);
        self::assertStringNotContainsString($run['id'], $raw, 'even the set id is not readable');

        // A different host: an empty backup folder and its own services.
        $other = new BackupPaths($this->b->tmp . '/other-host/backups');
        $backups = new BackupService($this->b->f->pdo, static fn(): PDO => Database::connect(TestDatabase::config()), $other, new BackupConfig(), $this->b->tmp . '/other-host/media', $this->b->clock, 'test', static fn(): array => []);
        $bundle = new OffsiteBundle($other, $backups);
        $id = $bundle->import($enc, self::PASS);
        self::assertSame($run['id'], $id);
        self::assertSame([], $backups->verify($id), 'the imported set verifies');
        self::assertSame(file_get_contents($this->b->paths->manifest($id)), file_get_contents($other->manifest($id)));
        self::assertSame(hash_file('sha256', $this->b->paths->dump($id)), hash_file('sha256', $other->dump($id)));

        // Importing it again changes nothing; a different set under the same id is a conflict.
        self::assertSame($id, $bundle->import($enc, self::PASS));
        try {
            $bundle->import($enc, 'not the right passphrase');
            self::fail('imported with the wrong passphrase');
        } catch (BackupError $e) {
            self::assertSame('passphrase', $e->reason);
        }
    }

    public function testAnImportRefusesEntriesThatAreNotPartOfABackupSet(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $id = $run['id'];
        $other = new BackupPaths($this->b->tmp . '/victim/backups');
        $backups = new BackupService($this->b->f->pdo, static fn(): PDO => Database::connect(TestDatabase::config()), $other, new BackupConfig(), $this->b->tmp . '/victim/media', $this->b->clock, 'test', static fn(): array => []);
        $bundle = new OffsiteBundle($other, $backups);

        foreach (['../../evil.php', '/tmp/evil', 'store/../../evil.png', 'db/other.sql.gz', 'config.php', '.env', 'store/2026/' . str_repeat('a', 64) . '.php'] as $bad) {
            $zip = $this->b->tmp . '/bad.zip';
            @unlink($zip);
            $z = new ZipArchive();
            $z->open($zip, ZipArchive::CREATE);
            $z->addFromString('bundle.json', json_encode(['format' => 'nova-bundle/1', 'id' => $id], JSON_THROW_ON_ERROR));
            $z->addFromString('manifest.json', (string) file_get_contents($this->b->paths->manifest($id)));
            $z->addFromString($bad, 'EVIL');
            $z->close();
            $enc = $this->b->tmp . '/bad.nova';
            @unlink($enc);
            OffsiteCrypto::encryptFile($zip, $enc, self::PASS, self::OPS, self::MEM);
            try {
                $bundle->import($enc, self::PASS);
                self::fail('imported an entry named ' . $bad);
            } catch (BackupError $e) {
                self::assertSame('traversal', $e->reason, $bad);
            }
        }
        self::assertFileDoesNotExist($this->b->tmp . '/evil.php');
        self::assertFileDoesNotExist($this->b->tmp . '/victim/backups/sets/' . $id . '.json', 'a refused import writes nothing');

        // A bundle whose dump was altered fails verification and is removed again.
        $good = $this->b->tmp . '/good.nova';
        (new OffsiteBundle($this->b->paths, $this->b->backups))->export($id, $good, self::PASS, self::OPS, self::MEM);
        $plainZip = $this->b->tmp . '/plain.zip';
        OffsiteCrypto::decryptFile($good, $plainZip, self::PASS);
        $z = new ZipArchive();
        $z->open($plainZip);
        $z->addFromString('db/' . $id . '.sql.gz', 'garbage');
        $z->close();
        $altered = $this->b->tmp . '/altered.nova';
        OffsiteCrypto::encryptFile($plainZip, $altered, self::PASS, self::OPS, self::MEM);
        try {
            $bundle->import($altered, self::PASS);
            self::fail('imported a bundle with an altered dump');
        } catch (BackupError $e) {
            self::assertSame('corrupt', $e->reason);
        }
        self::assertFileDoesNotExist($other->manifest($id));
        self::assertFileDoesNotExist($other->dump($id));
    }

    public function testOnlyAVerifiedSetIsExported(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        file_put_contents($this->b->paths->dump($run['id']), 'damaged');
        $this->expectException(BackupError::class);
        (new OffsiteBundle($this->b->paths, $this->b->backups))->export($run['id'], $this->b->tmp . '/x.nova', self::PASS, self::OPS, self::MEM);
    }
}
