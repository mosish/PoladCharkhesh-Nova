<?php

declare(strict_types=1);

namespace Nova\Media;

use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Publish\Clock;
use Nova\Security\Actor;
use PDO;

/**
 * The media library (frozen A4; 05 §12, §26): raster images and sanitised SVG.
 *
 * - Upload by content, never by name. A raster image is decoded and re-encoded
 *   (metadata gone, orientation applied) and named by the SHA-256 of what is
 *   stored; the same bytes are stored once. **SVG is Owner-only** and only
 *   through `SvgSanitizer`; an Editor uploads raster images or PDFs.
 * - Alt text in fa and en with an informative or decorative role. An
 *   informative item without both texts is reported by `missingAlt()` and
 *   blocks publishing while a published page uses it (ALT-INF, G2-15).
 * - Replacing a file keeps the row, so every reference survives. The
 *   superseded file stays on disk (releases and rollbacks still link to it)
 *   until the 30-day sweep.
 * - Deleting is blocked while referenced; a deleted item sits in the trash
 *   for 30 days and can be restored (`purgeTrash`).
 * - Every change is audited.
 */
final readonly class MediaService
{
    public function __construct(
        private PDO $pdo,
        private MediaStorage $storage,
        private MediaLimits $limits,
        private Clock $clock,
        private AuditLog $audit,
        /** @var (\Closure(): array<string, true>)|null SHA-256s that retained releases still link to; without it the file sweep does not run */
        private ?\Closure $retained = null,
    ) {}

    /**
     * @return array{id: int, existing: bool, removed: list<string>}
     *
     * @throws MediaError
     */
    public function upload(Actor $actor, string $originalName, string $bytes): array
    {
        $isSvg = self::looksLikeSvg($bytes);
        if ($isSvg && !$actor->isOwner()) {
            throw new MediaError('forbidden', 'Only the Owner can upload SVG. Upload a raster image or PDF, or ask the Owner.');
        }
        [$stored, $meta, $derivatives, $removed] = $this->prepare($bytes, $isSvg);
        $sha = hash('sha256', $stored);

        $existing = Sql::row($this->pdo, 'SELECT id, trashed_at FROM media WHERE sha256 = ?', [$sha]);
        if ($existing !== null) {
            if ($existing['trashed_at'] !== null) {
                $this->restore($actor, (int) $existing['id']);
            }

            return ['id' => (int) $existing['id'], 'existing' => true, 'removed' => $removed];
        }

        $year = (int) gmdate('Y', $this->clock->now());
        $this->storage->store($year, $sha, $meta['ext'], $stored, $derivatives);
        $name = self::safeName($originalName, $meta['ext']);
        $id = Transaction::run($this->pdo, function () use ($actor, $sha, $meta, $stored, $derivatives, $name, $year, $isSvg, $removed): int {
            Sql::run(
                $this->pdo,
                'INSERT INTO media (kind, sha256, ext, mime, width, height, byte_size, original_name, year, derivatives, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$isSvg ? 'svg' : 'raster', $sha, $meta['ext'], $meta['mime'], $meta['width'], $meta['height'], \strlen($stored), $name, $year, $derivatives === [] ? null : implode(',', array_keys($derivatives)), $actor->userId],
            );
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->record($actor, 'media.upload', 'media', $id, $name, null, ['kind' => $isSvg ? 'svg' : 'raster', 'sha256' => $sha, 'removed' => $removed]);

            return $id;
        });

        return ['id' => $id, 'existing' => false, 'removed' => $removed];
    }

    /** @throws MediaError */
    public function updateDescription(Actor $actor, int $id, int $expectedVersion, ?string $altFa, ?string $altEn, string $role): void
    {
        if (!\in_array($role, ['informative', 'decorative'], true)) {
            throw new MediaError('invalid', 'The role is informative or decorative.');
        }
        $altFa = self::alt($altFa);
        $altEn = self::alt($altEn);
        Transaction::run($this->pdo, function () use ($actor, $id, $expectedVersion, $altFa, $altEn, $role): void {
            $row = Sql::row($this->pdo, 'SELECT * FROM media WHERE id = ? FOR UPDATE', [$id]) ?? throw new MediaError('not_found', 'The media item does not exist.');
            if ((int) $row['row_version'] !== $expectedVersion) {
                throw new MediaError('stale', 'The item was changed by someone else; reload and try again.');
            }
            Sql::run($this->pdo, 'UPDATE media SET alt_fa = ?, alt_en = ?, role = ?, row_version = row_version + 1 WHERE id = ?', [$altFa, $altEn, $role, $id]);
            $this->audit->record($actor, 'media.update', 'media', $id, (string) $row['original_name'], ['alt_fa' => $row['alt_fa'], 'alt_en' => $row['alt_en'], 'role' => $row['role']], ['alt_fa' => $altFa, 'alt_en' => $altEn, 'role' => $role]);
        });
    }

    /**
     * Replaces the file of an item; its id, alt text and every reference stay.
     *
     * @return list<string> what an SVG replacement had removed
     *
     * @throws MediaError
     */
    public function replace(Actor $actor, int $id, string $originalName, string $bytes): array
    {
        $row = Sql::row($this->pdo, 'SELECT * FROM media WHERE id = ?', [$id]) ?? throw new MediaError('not_found', 'The media item does not exist.');
        $isSvg = $row['kind'] === 'svg';
        if (self::looksLikeSvg($bytes) !== $isSvg) {
            throw new MediaError('invalid', $isSvg ? 'An SVG can only be replaced by an SVG.' : 'A raster image can only be replaced by a raster image.');
        }
        if ($isSvg && !$actor->isOwner()) {
            throw new MediaError('forbidden', 'Only the Owner can upload SVG.');
        }
        [$stored, $meta, $derivatives, $removed] = $this->prepare($bytes, $isSvg);
        $sha = hash('sha256', $stored);
        if ($sha === $row['sha256']) {
            return $removed;
        }
        if (Sql::scalar($this->pdo, 'SELECT id FROM media WHERE sha256 = ?', [$sha]) !== null) {
            throw new MediaError('duplicate', 'This exact file is already in the library as another item.');
        }
        $year = (int) gmdate('Y', $this->clock->now());
        $this->storage->store($year, $sha, $meta['ext'], $stored, $derivatives);
        $name = self::safeName($originalName, $meta['ext']);
        Transaction::run($this->pdo, function () use ($actor, $id, $row, $sha, $meta, $stored, $derivatives, $name, $year, $removed): void {
            Sql::run(
                $this->pdo,
                'UPDATE media SET sha256 = ?, ext = ?, mime = ?, width = ?, height = ?, byte_size = ?, original_name = ?, year = ?, derivatives = ?, row_version = row_version + 1, trashed_at = NULL WHERE id = ?',
                [$sha, $meta['ext'], $meta['mime'], $meta['width'], $meta['height'], \strlen($stored), $name, $year, $derivatives === [] ? null : implode(',', array_keys($derivatives)), $id],
            );
            $this->audit->record($actor, 'media.replace', 'media', $id, $name, ['sha256' => $row['sha256']], ['sha256' => $sha, 'removed' => $removed]);
        });
        // The superseded file stays for releases that still link to it; the sweep counts from now.
        $this->touchAll((int) $row['year'], (string) $row['sha256'], (string) $row['ext'], (string) ($row['derivatives'] ?? ''));

        return $removed;
    }

    /** @throws MediaError referenced: the items that use it are in `detail` */
    public function trash(Actor $actor, int $id): void
    {
        $refs = (new MediaReferences($this->pdo))->ofMedia($id);
        if ($refs !== []) {
            throw new MediaError('referenced', 'The item is used and cannot be deleted. Remove it from these first: ' . implode(', ', array_column($refs, 'label')), array_column($refs, 'label'));
        }
        Transaction::run($this->pdo, function () use ($actor, $id): void {
            $row = Sql::row($this->pdo, 'SELECT original_name, trashed_at FROM media WHERE id = ? FOR UPDATE', [$id]) ?? throw new MediaError('not_found', 'The media item does not exist.');
            if ($row['trashed_at'] !== null) {
                return;
            }
            Sql::run($this->pdo, 'UPDATE media SET trashed_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?', [$id]);
            $this->audit->record($actor, 'media.trash', 'media', $id, (string) $row['original_name']);
        });
    }

    public function restore(Actor $actor, int $id): void
    {
        Transaction::run($this->pdo, function () use ($actor, $id): void {
            $row = Sql::row($this->pdo, 'SELECT original_name, trashed_at FROM media WHERE id = ? FOR UPDATE', [$id]) ?? throw new MediaError('not_found', 'The media item does not exist.');
            if ($row['trashed_at'] === null) {
                return;
            }
            Sql::run($this->pdo, 'UPDATE media SET trashed_at = NULL, row_version = row_version + 1 WHERE id = ?', [$id]);
            $this->audit->record($actor, 'media.restore', 'media', $id, (string) $row['original_name']);
        });
    }

    /**
     * Permanently removes items that have been in the trash for 30 days, then
     * sweeps files no record names.
     *
     * @return array{items: int, files: int}
     */
    public function purgeTrash(): array
    {
        $ids = array_map(intval(...), Sql::column($this->pdo, 'SELECT id FROM media WHERE trashed_at IS NOT NULL AND trashed_at < UTC_TIMESTAMP() - INTERVAL ' . MediaStorage::RETENTION_DAYS . ' DAY'));
        foreach ($ids as $id) {
            if ((new MediaReferences($this->pdo))->ofMedia($id) !== []) {
                continue; // referenced again since it was trashed: keep
            }
            Transaction::run($this->pdo, function () use ($id): void {
                Sql::run($this->pdo, 'DELETE FROM media WHERE id = ? AND trashed_at IS NOT NULL', [$id]);
                $this->audit->record(null, 'media.purge', 'media', $id, null);
            });
        }
        $files = 0;
        if ($this->retained !== null) {
            $pdo = $this->pdo;
            $retained = ($this->retained)();
            $files = $this->storage->sweep(static fn(string $sha): bool => isset($retained[$sha]) || Sql::scalar($pdo, 'SELECT 1 FROM media WHERE sha256 = ? UNION SELECT 1 FROM document WHERE sha256 = ?', [$sha, $sha]) !== null, $this->clock->now());
        }

        return ['items' => \count($ids), 'files' => $files];
    }

    /**
     * Informative items without alt text in both languages that a published
     * page uses: the ALT-INF publish blockers.
     *
     * @return list<string>
     */
    public function missingAlt(): array
    {
        return AltCheck::problems($this->pdo);
    }

    /** @return list<array<string, mixed>> */
    public function list(string $kind, string $q, bool $trashed, int $limit = 200): array
    {
        $where = [$trashed ? 'trashed_at IS NOT NULL' : 'trashed_at IS NULL'];
        $params = [];
        if (\in_array($kind, ['raster', 'svg'], true)) {
            $where[] = 'kind = ?';
            $params[] = $kind;
        }
        if ($q !== '') {
            $where[] = '(original_name LIKE ? OR alt_fa LIKE ? OR alt_en LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }

        return array_map(fn(array $r): array => $r + ['url' => $this->storage->url((int) $r['year'], (string) $r['sha256'], (string) $r['ext']), 'missing_alt' => $r['role'] === 'informative' && (trim((string) $r['alt_fa']) === '' || trim((string) $r['alt_en']) === '')], Sql::rows($this->pdo, 'SELECT * FROM media WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$limit}", $params));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $r = Sql::row($this->pdo, 'SELECT * FROM media WHERE id = ?', [$id]);

        return $r === null ? null : $r + ['url' => $this->storage->url((int) $r['year'], (string) $r['sha256'], (string) $r['ext']), 'references' => (new MediaReferences($this->pdo))->ofMedia($id)];
    }

    /**
     * @return array{string, array{ext: string, mime: string, width: int, height: int}, array<int, string>, list<string>} stored bytes, metadata, derivatives, removals
     */
    private function prepare(string $bytes, bool $isSvg): array
    {
        if ($isSvg) {
            $svg = SvgSanitizer::clean($bytes, $this->limits);

            return [$svg->svg, ['ext' => 'svg', 'mime' => 'image/svg+xml', 'width' => $svg->width, 'height' => $svg->height], [], $svg->removed];
        }
        $image = ImageProcessor::clean($bytes, $this->limits);

        return [$image['bytes'], ['ext' => $image['ext'], 'mime' => $image['mime'], 'width' => $image['width'], 'height' => $image['height']], ImageProcessor::derivatives($image['bytes']), []];
    }

    private function touchAll(int $year, string $sha, string $ext, string $derivatives): void
    {
        $this->storage->touch($year, $sha, $ext, $this->clock->now());
        foreach (array_filter(explode(',', $derivatives)) as $w) {
            $this->storage->touch($year, $sha, 'webp', $this->clock->now(), (int) $w);
        }
    }

    private static function looksLikeSvg(string $bytes): bool
    {
        return preg_match('/^(?:\xEF\xBB\xBF)?\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', substr($bytes, 0, 4096)) === 1
            || preg_match('/^(?:\xEF\xBB\xBF)?\s*<\?xml[^>]*>\s*(<!DOCTYPE\s+svg|<!--)/is', substr($bytes, 0, 4096)) === 1;
    }

    private static function alt(?string $alt): ?string
    {
        $alt = trim((string) $alt);
        if (mb_strlen($alt, 'UTF-8') > 300 || preg_match('/[\x00-\x1F\x7F]/', $alt) === 1) {
            throw new MediaError('invalid', 'Alt text is at most 300 characters on one line.');
        }

        return $alt === '' ? null : $alt;
    }

    /** A display name only: never used as a path. */
    private static function safeName(string $name, string $ext): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '', basename(str_replace('\\', '/', $name))));
        $name = mb_substr($name, 0, 200, 'UTF-8');

        return $name === '' ? "upload.{$ext}" : $name;
    }
}
