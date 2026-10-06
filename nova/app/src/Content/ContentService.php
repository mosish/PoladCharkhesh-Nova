<?php

declare(strict_types=1);

namespace Nova\Content;

use Nova\Audit\AuditLog;
use Nova\Catalogue\CatalogueError;
use Nova\Content\RichText\Profile;
use Nova\Content\RichText\Schema;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Security\Actor;
use PDO;

/** Rich-content fields (layer 2 of D22). Every save is validated against the field's profile. */
final readonly class ContentService
{
    public const Profile PRODUCT_DESCRIPTION = Profile::Basic;

    public function __construct(private PDO $pdo, private AuditLog $audit) {}

    /** @param array<array-key, mixed>|null $document null clears the field */
    public function setProductDescription(Actor $actor, int $productId, int $expectedVersion, string $language, ?array $document): void
    {
        if (!\in_array($language, ['fa', 'en'], true)) {
            throw CatalogueError::invalid("Unknown language \"{$language}\".");
        }
        if ($document !== null) {
            $errors = Schema::errors($document, self::PRODUCT_DESCRIPTION);
            if ($errors !== []) {
                throw CatalogueError::invalid('The description is not valid rich content: ' . implode('; ', $errors));
            }
        }
        $json = $document === null ? null : json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        Transaction::run($this->pdo, function () use ($actor, $productId, $expectedVersion, $language, $json): void {
            $column = "description_{$language}";
            $before = Sql::row($this->pdo, "SELECT designation, {$column} AS doc, row_version FROM product WHERE id = ? FOR UPDATE", [$productId]) ?? throw CatalogueError::notFound('Product');
            if ((int) $before['row_version'] !== $expectedVersion) {
                throw CatalogueError::stale('Product');
            }
            Sql::run($this->pdo, "UPDATE product SET {$column} = ?, row_version = row_version + 1 WHERE id = ?", [$json, $productId]);
            $this->audit->record($actor, 'content.product_description', 'product', $productId, (string) $before['designation'], ['language' => $language, 'document' => $before['doc']], ['language' => $language, 'document' => $json]);
        });
    }

    /** @return array<array-key, mixed>|null */
    public function productDescription(int $productId, string $language): ?array
    {
        $json = Sql::scalar($this->pdo, 'SELECT ' . ($language === 'fa' ? 'description_fa' : 'description_en') . ' FROM product WHERE id = ?', [$productId]);
        if ($json === null) {
            return null;
        }
        $document = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

        return \is_array($document) ? $document : null;
    }
}
