<?php

declare(strict_types=1);

namespace Nova\Backup;

/**
 * Authenticated encryption for off-site copies (05 §15, §18; E-11), built on
 * libsodium so no key ever has to live in a file:
 *
 * - The key comes from a passphrase the Owner types or pipes in (Argon2id,
 *   fresh random salt per file). Nothing is stored that could recover it.
 * - The file is a stream of XChaCha20-Poly1305 chunks. Any change, reordering
 *   or truncation is detected, and a wrong passphrase fails at the first
 *   chunk; the final chunk is tagged, so a file cut short is refused.
 * - The Argon2id cost is written into the file, but only values inside safe
 *   bounds are accepted when reading, so a crafted file cannot exhaust memory.
 *
 * Losing the passphrase loses the copy; there is no recovery by design.
 */
final class OffsiteCrypto
{
    public const string MAGIC = "NOVABK1\n";
    public const int MIN_PASSPHRASE = 12;
    private const int CHUNK = 65_536;
    private const int MAX_OPS = 12;
    private const int MAX_MEM = 1_073_741_824;

    public static function encryptFile(string $in, string $out, #[\SensitiveParameter] string $passphrase, int $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, int $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE): void
    {
        if (mb_strlen($passphrase, '8bit') < self::MIN_PASSPHRASE) {
            throw new BackupError('passphrase', 'The passphrase must be at least ' . self::MIN_PASSPHRASE . ' characters.');
        }
        $src = @fopen($in, 'rb');
        $part = $out . '.part';
        $dst = @fopen($part, 'wb');
        if ($src === false || $dst === false) {
            throw new BackupError('io', 'Cannot open the files to encrypt.');
        }
        @chmod($part, 0600);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        /** @var array{string, string} $init */
        $init = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        [$state, $header] = $init;
        sodium_memzero($key);
        fwrite($dst, self::MAGIC . pack('NN', $ops, intdiv($mem, 1024)) . $salt . $header);
        $chunk = (string) fread($src, self::CHUNK);
        while (true) {
            $next = feof($src) ? '' : (string) fread($src, self::CHUNK);
            $final = $next === '' && feof($src);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, self::MAGIC, $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($dst, pack('N', \strlen($cipher)) . $cipher);
            if ($final) {
                break;
            }
            $chunk = $next;
        }
        fflush($dst);
        fclose($src);
        fclose($dst);
        if (!rename($part, $out)) {
            @unlink($part);

            throw new BackupError('io', 'Cannot write the encrypted file.');
        }
        @chmod($out, 0600);
    }

    /** @throws BackupError passphrase (wrong passphrase or damaged file), corrupt, incomplete */
    public static function decryptFile(string $in, string $out, #[\SensitiveParameter] string $passphrase): void
    {
        $src = @fopen($in, 'rb');
        if ($src === false) {
            throw new BackupError('not_found', 'The encrypted file cannot be opened.');
        }
        $head = (string) fread($src, \strlen(self::MAGIC) + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $magicLen = \strlen(self::MAGIC);
        if (!str_starts_with($head, self::MAGIC) || \strlen($head) < $magicLen + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            fclose($src);

            throw new BackupError('corrupt', 'This is not a Nova encrypted backup.');
        }
        /** @var array{ops: int, kib: int} $p */
        $p = unpack('Nops/Nkib', substr($head, $magicLen, 8));
        $salt = substr($head, $magicLen + 8, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $header = substr($head, $magicLen + 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES);
        if ($p['ops'] < 1 || $p['ops'] > self::MAX_OPS || $p['kib'] < 8 || $p['kib'] * 1024 > self::MAX_MEM) {
            fclose($src);

            throw new BackupError('corrupt', 'The encrypted file declares unsafe key-derivation settings.');
        }
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, $p['ops'], $p['kib'] * 1024, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        sodium_memzero($key);
        $part = $out . '.part';
        $dst = @fopen($part, 'wb');
        if ($dst === false) {
            fclose($src);

            throw new BackupError('io', 'Cannot write the decrypted file.');
        }
        @chmod($part, 0600);
        $first = true;
        $finished = false;
        try {
            while (!$finished) {
                $len = fread($src, 4);
                if ($len === false || $len === '') {
                    throw new BackupError('incomplete', 'The encrypted file is cut short.');
                }
                if (\strlen($len) !== 4) {
                    throw new BackupError('incomplete', 'The encrypted file is cut short.');
                }
                /** @var array{n: int} $n */
                $n = unpack('Nn', $len);
                if ($n['n'] < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n['n'] > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new BackupError('corrupt', 'The encrypted file is damaged.');
                }
                $cipher = (string) fread($src, $n['n']);
                if (\strlen($cipher) !== $n['n']) {
                    throw new BackupError('incomplete', 'The encrypted file is cut short.');
                }
                /** @var array{string, int}|false $plain */
                $plain = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, self::MAGIC);
                if ($plain === false) {
                    throw new BackupError($first ? 'passphrase' : 'corrupt', $first ? 'Wrong passphrase, or the file is damaged.' : 'The encrypted file is damaged or was altered.');
                }
                $first = false;
                fwrite($dst, $plain[0]);
                $finished = $plain[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (fread($src, 1) !== '' && !feof($src)) {
                throw new BackupError('corrupt', 'The encrypted file has data after its end.');
            }
        } catch (\Throwable $e) {
            fclose($src);
            fclose($dst);
            @unlink($part);

            throw $e;
        }
        fclose($src);
        fflush($dst);
        fclose($dst);
        if (!rename($part, $out)) {
            @unlink($part);

            throw new BackupError('io', 'Cannot write the decrypted file.');
        }
    }
}
