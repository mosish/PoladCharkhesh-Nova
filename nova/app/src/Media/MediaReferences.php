<?php

declare(strict_types=1);

namespace Nova\Media;

use Nova\Content\RichText\Renderer;
use Nova\Core\Sql;
use PDO;

/**
 * Where a media item or a document is used (05 §12). Nothing is stored:
 * references are read from the rich content and from document links, so
 * they cannot disagree with the content. Deleting is blocked while any
 * exist.
 */
final readonly class MediaReferences
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array{type: string, id: int, label: string}> */
    public function ofMedia(int $id): array
    {
        return $this->inContent('media:' . $id);
    }

    /** @return list<array{type: string, id: int, label: string}> */
    public function ofDocument(int $id): array
    {
        $refs = $this->inContent('document:' . $id);
        foreach (Sql::rows($this->pdo, 'SELECT l.product_id, l.variant_id, COALESCE(p.designation, v.designation) AS label FROM document_link l LEFT JOIN product p ON p.id = l.product_id LEFT JOIN product_variant v ON v.id = l.variant_id WHERE l.document_id = ?', [$id]) as $row) {
            $refs[] = $row['product_id'] !== null
                ? ['type' => 'product', 'id' => (int) $row['product_id'], 'label' => (string) $row['label']]
                : ['type' => 'variant', 'id' => (int) $row['variant_id'], 'label' => (string) $row['label']];
        }

        return $refs;
    }

    /**
     * Media ids used by figures in published rich content, for the publish checks.
     *
     * @return array<int, list<string>> media id => the Products that use it
     */
    public function usedByPublished(): array
    {
        $used = [];
        foreach (Sql::rows($this->pdo, "SELECT designation, description_fa, description_en FROM product WHERE status = 'published'") as $p) {
            foreach (['description_fa', 'description_en'] as $field) {
                foreach (self::referencesIn($p[$field]) as $ref) {
                    if (str_starts_with($ref, 'media:')) {
                        $used[(int) substr($ref, 6)][] = (string) $p['designation'];
                    }
                }
            }
        }

        return array_map(static fn(array $products): array => array_values(array_unique($products)), $used);
    }

    /** @return list<array{type: string, id: int, label: string}> */
    private function inContent(string $ref): array
    {
        $found = [];
        foreach (Sql::rows($this->pdo, 'SELECT id, designation, description_fa, description_en FROM product WHERE description_fa IS NOT NULL OR description_en IS NOT NULL') as $p) {
            if (\in_array($ref, [...self::referencesIn($p['description_fa']), ...self::referencesIn($p['description_en'])], true)) {
                $found[] = ['type' => 'product', 'id' => (int) $p['id'], 'label' => (string) $p['designation']];
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function referencesIn(mixed $json): array
    {
        $doc = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($doc) ? Renderer::references($doc) : [];
    }
}
