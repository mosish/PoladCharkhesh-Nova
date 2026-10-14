<?php

declare(strict_types=1);

namespace Nova\Media;

use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Designation\Normaliser;
use Nova\Publish\Clock;
use Nova\Security\Actor;
use PDO;
use PDOException;

/**
 * PDF documents and their links (frozen A4; 05 §12): upload by content,
 * titles in both languages, and attachment to a Product or to one variant
 * (DocumentLink, never to both), per language, with the size recorded so it
 * can be shown before download. A link names exactly one designation; no
 * document is ever shared between designations implicitly (D20/E-2).
 */
final readonly class DocumentService
{
    public function __construct(
        private PDO $pdo,
        private MediaStorage $storage,
        private MediaLimits $limits,
        private Clock $clock,
        private AuditLog $audit,
    ) {}

    /**
     * @return array{id: int, existing: bool}
     *
     * @throws MediaError
     */
    public function upload(Actor $actor, string $originalName, string $bytes, ?string $titleFa, ?string $titleEn): array
    {
        PdfInspector::check($bytes, $this->limits);
        $sha = hash('sha256', $bytes);
        $existing = Sql::row($this->pdo, 'SELECT id, trashed_at FROM document WHERE sha256 = ?', [$sha]);
        if ($existing !== null) {
            if ($existing['trashed_at'] !== null) {
                $this->restore($actor, (int) $existing['id']);
            }

            return ['id' => (int) $existing['id'], 'existing' => true];
        }
        $year = (int) gmdate('Y', $this->clock->now());
        $this->storage->store($year, $sha, 'pdf', $bytes);
        $name = self::safeName($originalName);
        $titleFa = self::title($titleFa);
        $titleEn = self::title($titleEn);
        $id = Transaction::run($this->pdo, function () use ($actor, $sha, $bytes, $name, $year, $titleFa, $titleEn): int {
            Sql::run($this->pdo, "INSERT INTO document (sha256, original_name, mime, byte_size, year, uploaded_by, title_fa, title_en) VALUES (?, ?, 'application/pdf', ?, ?, ?, ?, ?)", [$sha, $name, \strlen($bytes), $year, $actor->userId, $titleFa, $titleEn]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->record($actor, 'document.upload', 'document', $id, $name, null, ['sha256' => $sha, 'bytes' => \strlen($bytes)]);

            return $id;
        });

        return ['id' => $id, 'existing' => false];
    }

    /** @throws MediaError */
    public function setTitles(Actor $actor, int $id, int $expectedVersion, ?string $titleFa, ?string $titleEn): void
    {
        $titleFa = self::title($titleFa);
        $titleEn = self::title($titleEn);
        Transaction::run($this->pdo, function () use ($actor, $id, $expectedVersion, $titleFa, $titleEn): void {
            $row = Sql::row($this->pdo, 'SELECT * FROM document WHERE id = ? FOR UPDATE', [$id]) ?? throw new MediaError('not_found', 'The document does not exist.');
            if ((int) $row['row_version'] !== $expectedVersion) {
                throw new MediaError('stale', 'The document was changed by someone else; reload and try again.');
            }
            Sql::run($this->pdo, 'UPDATE document SET title_fa = ?, title_en = ?, row_version = row_version + 1 WHERE id = ?', [$titleFa, $titleEn, $id]);
            $this->audit->record($actor, 'document.update', 'document', $id, (string) $row['original_name'], ['title_fa' => $row['title_fa'], 'title_en' => $row['title_en']], ['title_fa' => $titleFa, 'title_en' => $titleEn]);
        });
    }

    /**
     * Attaches a document to the Product or variant whose designation is
     * `$designation` (its normalised key, as in search). A spelling is not a
     * designation of its own and cannot carry a document.
     *
     * @throws MediaError
     */
    public function link(Actor $actor, int $id, string $designation, string $language, string $role = 'document'): int
    {
        if (!\in_array($language, ['fa', 'en', 'both'], true)) {
            throw new MediaError('invalid', 'The language is fa, en or both.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $role) !== 1) {
            throw new MediaError('invalid', 'The role is a short lower-case word.');
        }
        $key = Normaliser::key(trim($designation));
        $target = $key === '' ? null : Sql::row($this->pdo, 'SELECT product_id, variant_id FROM designation_key WHERE norm_key = ? AND spelling_id IS NULL', [$key]);
        if ($target === null) {
            throw new MediaError('not_found', 'No Product or variant has this designation. A spelling cannot carry a document.');
        }

        return Transaction::run($this->pdo, function () use ($actor, $id, $target, $language, $role, $designation): int {
            $doc = Sql::row($this->pdo, 'SELECT original_name, trashed_at FROM document WHERE id = ? FOR UPDATE', [$id]) ?? throw new MediaError('not_found', 'The document does not exist.');
            if ($doc['trashed_at'] !== null) {
                throw new MediaError('invalid', 'The document is in the trash; restore it first.');
            }
            try {
                Sql::run($this->pdo, 'INSERT INTO document_link (document_id, product_id, variant_id, language, role) VALUES (?, ?, ?, ?, ?)', [$id, $target['product_id'], $target['variant_id'], $language, $role]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new MediaError('duplicate', 'This document is already attached to this designation in this language.');
                }
                throw $e;
            }
            $link = (int) $this->pdo->lastInsertId();
            $this->audit->record($actor, 'document.link', 'document', $id, (string) $doc['original_name'], null, ['designation' => trim($designation), 'language' => $language, 'product_id' => $target['product_id'], 'variant_id' => $target['variant_id']]);

            return $link;
        });
    }

    public function unlink(Actor $actor, int $linkId): void
    {
        Transaction::run($this->pdo, function () use ($actor, $linkId): void {
            $link = Sql::row($this->pdo, 'SELECT l.document_id, d.original_name FROM document_link l JOIN document d ON d.id = l.document_id WHERE l.id = ? FOR UPDATE', [$linkId]) ?? throw new MediaError('not_found', 'The link does not exist.');
            Sql::run($this->pdo, 'DELETE FROM document_link WHERE id = ?', [$linkId]);
            $this->audit->record($actor, 'document.unlink', 'document', (int) $link['document_id'], (string) $link['original_name']);
        });
    }

    /** @throws MediaError referenced while linked or used in content */
    public function trash(Actor $actor, int $id): void
    {
        $refs = (new MediaReferences($this->pdo))->ofDocument($id);
        if ($refs !== []) {
            throw new MediaError('referenced', 'The document is used and cannot be deleted. Detach it from these first: ' . implode(', ', array_column($refs, 'label')), array_column($refs, 'label'));
        }
        Transaction::run($this->pdo, function () use ($actor, $id): void {
            $row = Sql::row($this->pdo, 'SELECT original_name, trashed_at FROM document WHERE id = ? FOR UPDATE', [$id]) ?? throw new MediaError('not_found', 'The document does not exist.');
            if ($row['trashed_at'] !== null) {
                return;
            }
            Sql::run($this->pdo, 'UPDATE document SET trashed_at = UTC_TIMESTAMP(), row_version = row_version + 1 WHERE id = ?', [$id]);
            $this->audit->record($actor, 'document.trash', 'document', $id, (string) $row['original_name']);
        });
    }

    public function restore(Actor $actor, int $id): void
    {
        Transaction::run($this->pdo, function () use ($actor, $id): void {
            $row = Sql::row($this->pdo, 'SELECT original_name, trashed_at FROM document WHERE id = ? FOR UPDATE', [$id]) ?? throw new MediaError('not_found', 'The document does not exist.');
            if ($row['trashed_at'] === null) {
                return;
            }
            Sql::run($this->pdo, 'UPDATE document SET trashed_at = NULL, row_version = row_version + 1 WHERE id = ?', [$id]);
            $this->audit->record($actor, 'document.restore', 'document', $id, (string) $row['original_name']);
        });
    }

    /** Permanently removes documents trashed for 30 days (their files go with the media sweep). */
    public function purgeTrash(): int
    {
        $ids = array_map(intval(...), Sql::column($this->pdo, 'SELECT id FROM document WHERE trashed_at IS NOT NULL AND trashed_at < UTC_TIMESTAMP() - INTERVAL ' . MediaStorage::RETENTION_DAYS . ' DAY'));
        $purged = 0;
        foreach ($ids as $id) {
            if ((new MediaReferences($this->pdo))->ofDocument($id) !== [] || Sql::scalar($this->pdo, 'SELECT 1 FROM source WHERE document_id = ?', [$id]) !== null) {
                continue;
            }
            Transaction::run($this->pdo, function () use ($id): void {
                Sql::run($this->pdo, 'DELETE FROM document WHERE id = ? AND trashed_at IS NOT NULL', [$id]);
                $this->audit->record(null, 'document.purge', 'document', $id, null);
            });
            ++$purged;
        }

        return $purged;
    }

    /** @return list<array<string, mixed>> */
    public function list(bool $trashed, string $q, int $limit = 200): array
    {
        $where = $trashed ? 'd.trashed_at IS NOT NULL' : 'd.trashed_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (d.original_name LIKE ? OR d.title_fa LIKE ? OR d.title_en LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = [$like, $like, $like];
        }

        return array_map(fn(array $r): array => $r + ['url' => $this->url($r)], Sql::rows($this->pdo, "SELECT d.*, (SELECT COUNT(*) FROM document_link l WHERE l.document_id = d.id) AS links FROM document d WHERE {$where} ORDER BY d.id DESC LIMIT {$limit}", $params));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $r = Sql::row($this->pdo, 'SELECT * FROM document WHERE id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        $links = Sql::rows($this->pdo, 'SELECT l.id, l.language, l.role, l.product_id, l.variant_id, COALESCE(p.designation, v.designation) AS designation FROM document_link l LEFT JOIN product p ON p.id = l.product_id LEFT JOIN product_variant v ON v.id = l.variant_id WHERE l.document_id = ? ORDER BY l.id', [$id]);

        return $r + ['url' => $this->url($r), 'links' => $links, 'references' => (new MediaReferences($this->pdo))->ofDocument($id)];
    }

    /** @param array<string, mixed> $row */
    private function url(array $row): string
    {
        $year = $row['year'] ?? null;
        $sha = $row['sha256'] ?? null;

        return $this->storage->url(is_numeric($year) ? (int) $year : (int) gmdate('Y'), is_string($sha) ? $sha : '', 'pdf');
    }

    private static function title(?string $title): ?string
    {
        $title = trim((string) $title);
        if (mb_strlen($title, 'UTF-8') > 200 || preg_match('/[\x00-\x1F\x7F]/', $title) === 1) {
            throw new MediaError('invalid', 'A title is at most 200 characters on one line.');
        }

        return $title === '' ? null : $title;
    }

    private static function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '', basename(str_replace('\\', '/', $name))));
        $name = mb_substr($name, 0, 200, 'UTF-8');

        return $name === '' ? 'document.pdf' : $name;
    }
}
