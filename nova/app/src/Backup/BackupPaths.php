<?php

declare(strict_types=1);

namespace Nova\Backup;

/**
 * The backup folder (05 §18), always outside the document root and private
 * (0700, files 0600):
 *
 *   <root>/sets/B-….json         one manifest per backup set (the catalogue; survives a lost database)
 *   <root>/sets/B-….json.sha256  its checksum
 *   <root>/db/B-….sql.gz         the database dump of that set
 *   <root>/store/<year>/<name>   media and document files, content-addressed, shared by every set (incremental)
 *   <root>/archive/…zip          full media archives
 *   <root>/tmp/                  work in progress; swept on start
 *   <root>/backup.lock           one backup or restore at a time
 */
final readonly class BackupPaths
{
    public const string ID_PATTERN = '/^B-[0-9]{8}-[0-9]{6}-[0-9a-f]{4}$/';

    public function __construct(public string $root) {}

    public static function validId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    public function sets(): string
    {
        return $this->root . '/sets';
    }

    public function manifest(string $id): string
    {
        return $this->sets() . '/' . self::checked($id) . '.json';
    }

    public function dump(string $id): string
    {
        return $this->root . '/db/' . self::checked($id) . '.sql.gz';
    }

    public function store(): string
    {
        return $this->root . '/store';
    }

    public function archives(): string
    {
        return $this->root . '/archive';
    }

    public function tmp(): string
    {
        return $this->root . '/tmp';
    }

    public function lockFile(): string
    {
        return $this->root . '/backup.lock';
    }

    public function ensure(): void
    {
        foreach ([$this->root, $this->sets(), $this->root . '/db', $this->store(), $this->archives(), $this->tmp()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new BackupError('io', "The backup folder {$dir} cannot be created.");
            }
        }
    }

    private static function checked(string $id): string
    {
        if (!self::validId($id)) {
            throw new BackupError('traversal', 'A backup set id looks like B-20261015-031500-ab12.');
        }

        return $id;
    }
}
