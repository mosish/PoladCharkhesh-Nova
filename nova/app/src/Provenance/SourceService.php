<?php

declare(strict_types=1);

namespace Nova\Provenance;

use Nova\Audit\AuditLog;
use Nova\Catalogue\CatalogueError;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Security\Actor;
use PDO;

/**
 * Sources are reusable, citable entities (A3 "quick source", 05 §11): a title
 * and an http(s) address (documents arrive with step 11). Owner and Editor
 * may add one; citing it on a value is what carries provenance.
 */
final readonly class SourceService
{
    public function __construct(private PDO $pdo, private AuditLog $audit) {}

    public function create(Actor $actor, string $title, string $url, ?string $publisher = null, ?string $edition = null): int
    {
        $title = trim($title);
        $url = trim($url);
        $publisher = self::optional($publisher, 160);
        $edition = self::optional($edition, 60);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
            throw CatalogueError::invalid('A source needs a title (at most 255 characters).');
        }
        $parts = parse_url($url);
        if (\strlen($url) > 2048 || !\is_array($parts) || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw CatalogueError::invalid('A source needs a web address starting with https:// or http://.');
        }

        return Transaction::run($this->pdo, function () use ($actor, $title, $url, $publisher, $edition): int {
            Sql::run($this->pdo, 'INSERT INTO source (title, publisher, edition, url) VALUES (?, ?, ?, ?)', [$title, $publisher, $edition, $url]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->record($actor, 'source.create', 'source', $id, $title, null, ['title' => $title, 'publisher' => $publisher, 'edition' => $edition, 'url' => $url]);

            return $id;
        });
    }

    private static function optional(?string $value, int $max): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw CatalogueError::invalid("A source field is longer than {$max} characters.");
        }

        return $value;
    }
}
