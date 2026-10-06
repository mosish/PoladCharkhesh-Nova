<?php

declare(strict_types=1);

namespace Nova\Catalogue;

use Nova\Core\Sql;
use Nova\Designation\Normaliser;
use Nova\Designation\RequestedDesignation;
use PDO;
use PDOException;

/**
 * The single designation-key namespace (designation_key). Every Product,
 * variant and spelling claims its normalised key here inside the same
 * transaction as the record itself; the UNIQUE index is the final guard.
 */
final readonly class DesignationKeys
{
    public function __construct(private PDO $pdo) {}

    /**
     * Validates a designation or spelling as typed by an editor and returns it
     * trimmed. At most 64 code points, so every catalogue designation can be
     * matched by a request (the requested-designation limit).
     */
    public static function clean(string $designation): string
    {
        Normaliser::assertUtf8($designation);
        $ws = Normaliser::WHITESPACE;
        $trimmed = (string) preg_replace("/^[{$ws}]+|[{$ws}]+\$/u", '', $designation);
        if ($trimmed === '') {
            throw CatalogueError::invalid('A designation is required.');
        }
        if (mb_strlen($trimmed, 'UTF-8') > RequestedDesignation::MAX_LENGTH) {
            throw CatalogueError::invalid('A designation can be at most ' . RequestedDesignation::MAX_LENGTH . ' characters.');
        }
        if (Normaliser::key($trimmed) === '') {
            throw CatalogueError::invalid("\"{$trimmed}\" has no characters left after normalisation.");
        }

        return $trimmed;
    }

    /** @param 'product'|'variant'|'spelling' $kind */
    public function claim(string $designation, string $kind, int $id): void
    {
        $key = Normaliser::key($designation);
        $this->assertFree($designation, $key, null);
        try {
            Sql::run($this->pdo, "INSERT INTO designation_key (norm_key, {$kind}_id) VALUES (?, ?)", [$key, $id]);
        } catch (PDOException $e) {
            throw $this->translate($e, $designation, $key);
        }
    }

    /** @param 'product'|'variant'|'spelling' $kind */
    public function rekey(string $designation, string $kind, int $id): void
    {
        $key = Normaliser::key($designation);
        $this->assertFree($designation, $key, "{$kind[0]}:{$id}");
        try {
            Sql::run($this->pdo, "UPDATE designation_key SET norm_key = ? WHERE {$kind}_id = ?", [$key, $id]);
        } catch (PDOException $e) {
            throw $this->translate($e, $designation, $key);
        }
    }

    /** The designation that already owns `$key`, written as the catalogue writes it, or null. */
    public function owner(string $key): ?string
    {
        $value = Sql::scalar($this->pdo, <<<'SQL'
            SELECT COALESCE(p.designation, v.designation, s.spelling)
            FROM designation_key k
            LEFT JOIN product p ON p.id = k.product_id
            LEFT JOIN product_variant v ON v.id = k.variant_id
            LEFT JOIN spelling s ON s.id = k.spelling_id
            WHERE k.norm_key = ?
            SQL, [$key]);

        return $value === null ? null : (string) $value;
    }

    private function assertFree(string $designation, string $key, ?string $self): void
    {
        $row = Sql::row($this->pdo, 'SELECT owner_ref FROM designation_key WHERE norm_key = ?', [$key]);
        if ($row !== null && $row['owner_ref'] !== $self) {
            throw CatalogueError::collision($designation, (string) $this->owner($key));
        }
    }

    private function translate(PDOException $e, string $designation, string $key): \Throwable
    {
        if (str_contains($e->getMessage(), 'uq_designation_key_norm')) {
            return CatalogueError::collision($designation, (string) $this->owner($key));
        }

        return $e;
    }
}
