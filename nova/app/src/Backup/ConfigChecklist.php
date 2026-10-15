<?php

declare(strict_types=1);

namespace Nova\Backup;

/**
 * The boundary of what a backup holds (05 §18): configuration and secrets are
 * never in a backup. This is the list of *names* the Owner needs to rebuild
 * `config.php` on a fresh host from the password manager where the values
 * live. It prints no values and reads none.
 */
final class ConfigChecklist
{
    /** @return list<array{key: string, needed: string, note: string}> */
    public static function keys(): array
    {
        return [
            ['key' => 'environment', 'needed' => 'always', 'note' => 'development, staging or production'],
            ['key' => 'database.host / port / name / user / password', 'needed' => 'always', 'note' => 'a new empty database for a restore'],
            ['key' => 'hosts.fa / hosts.en', 'needed' => 'staging, production', 'note' => 'the two language origins (D6)'],
            ['key' => 'paths.public / paths.storage', 'needed' => 'production', 'note' => 'document root and private storage (outside the root)'],
            ['key' => 'release_strategy', 'needed' => 'production', 'note' => 'symlink or slots (E-20)'],
            ['key' => 'admin_host', 'needed' => 'production', 'note' => 'the .ir host that serves /admin'],
            ['key' => 'inquiry.key', 'needed' => 'production', 'note' => 'at least 32 random characters; new on a new host is fine'],
            ['key' => 'smtp.host / port / user / password / encryption', 'needed' => 'production', 'note' => 'the notification mailbox'],
            ['key' => 'mail.from', 'needed' => 'production', 'note' => 'sender address'],
            ['key' => 'backup.dir / fallback / keep / overdue_hours', 'needed' => 'optional', 'note' => 'backup location, fallback trigger, retention'],
            ['key' => 'media.max_image_bytes / max_pixels / max_pdf_bytes / max_svg_bytes', 'needed' => 'optional', 'note' => 'upload safety caps'],
        ];
    }

    /**
     * What a backup does and does not hold.
     *
     * @return array{included: list<string>, excluded: list<string>}
     */
    public static function boundaries(): array
    {
        return [
            'included' => [
                'the database (schema and data of one consistent snapshot, without the tables below)',
                'media and document files the database records, with a checksum manifest (incremental store)',
                'the list of retained releases at backup time (ids and checksums only)',
            ],
            'excluded' => [
                'configuration and secrets (config.php, .env, SMTP and database passwords, inquiry.key): kept by the Owner in a password manager',
                'PHP session files',
                'the application code, vendor and generated assets (redeploy from Git / the deploy package)',
                'generated releases and the release switch (_site): static output, rebuilt by publishing; rollback copies are separate',
                'the throttle tables login_throttle and inquiry_attempt (short-lived, keyed by hashed addresses)',
            ],
        ];
    }
}
