<?php

declare(strict_types=1);

namespace Nova\Designation;

/**
 * The published catalogue as the matcher sees it (docs/designation-matching.md §3):
 * one namespace of normalised keys across Products, variants and spellings, plus
 * the Owner-approved suffix tokens per family. Relationships exist only as
 * recorded variants and spellings; nothing is derived from designation text.
 */
final class DesignationIndex
{
    /** @var array<string, IndexedProduct|IndexedVariant|IndexedSpelling> */
    private array $byKey = [];

    /** @var list<IndexedProduct> */
    private array $products = [];

    /** @var array<string, IndexedProduct> */
    private array $productsById = [];

    /** @var array<string, list<string>> family => normalised tokens */
    private array $suffixTokens = [];

    private function __construct() {}

    /** Builds the index from its published array form (the corpus `index` shape). */
    public static function fromArray(mixed $data): self
    {
        $index = new self();
        $root = self::map($data, 'index');
        $seenIds = [];

        foreach (self::list($root, 'products') as $i => $raw) {
            $product = self::map($raw, "products[{$i}]");
            $productId = self::string($product, 'id');
            self::claimId($seenIds, $productId);
            $designation = self::string($product, 'designation');
            $productKey = self::designationKey($designation);

            $variants = [];
            foreach (self::list($product, 'variants') as $j => $rawVariant) {
                $variant = self::map($rawVariant, "products[{$i}].variants[{$j}]");
                $variantId = self::string($variant, 'id');
                self::claimId($seenIds, $variantId);
                $variantDesignation = self::string($variant, 'designation');
                $variants[] = new IndexedVariant($variantId, $productId, $variantDesignation, self::designationKey($variantDesignation), self::bool($variant, 'listed'));
            }

            $indexed = new IndexedProduct($productId, $designation, $productKey, self::string($product, 'family'), self::bool($product, 'base_listed'), $variants);
            $index->claimKey($productKey, $indexed);
            foreach ($variants as $variant) {
                $index->claimKey($variant->key, $variant);
            }
            $index->products[] = $indexed;
            $index->productsById[$productId] = $indexed;
        }

        $variantsById = [];
        foreach ($index->products as $product) {
            foreach ($product->variants as $variant) {
                $variantsById[$variant->id] = $variant;
            }
        }

        foreach (self::list($root, 'spellings') as $i => $raw) {
            $spelling = self::map($raw, "spellings[{$i}]");
            $text = self::string($spelling, 'spelling');
            $targetId = self::string($spelling, 'target');
            $target = $index->productsById[$targetId] ?? $variantsById[$targetId]
                ?? throw new InvalidDesignationIndex('unknown_target', "spelling \"{$text}\" targets unknown id \"{$targetId}\"");
            $key = self::designationKey($text);
            $index->claimKey($key, new IndexedSpelling($text, $key, $target));
        }

        foreach (self::map($root['suffix_rules'] ?? null, 'suffix_rules') as $family => $tokens) {
            if (!\is_array($tokens) || !array_is_list($tokens)) {
                throw new InvalidDesignationIndex('invalid_index', "suffix_rules.{$family} must be a list");
            }
            $keys = [];
            foreach ($tokens as $token) {
                if (!\is_string($token)) {
                    throw new InvalidDesignationIndex('invalid_index', "suffix_rules.{$family} must contain strings");
                }
                $tokenKey = Normaliser::key($token);
                if ($tokenKey === '') {
                    throw new InvalidDesignationIndex('empty_token', "suffix token \"{$token}\" normalises to nothing");
                }
                $keys[$tokenKey] = true;
            }
            $index->suffixTokens[(string) $family] = array_map(strval(...), array_keys($keys));
        }

        return $index;
    }

    public function find(string $key): IndexedProduct|IndexedVariant|IndexedSpelling|null
    {
        return $this->byKey[$key] ?? null;
    }

    /** @return list<IndexedProduct> */
    public function products(): array
    {
        return $this->products;
    }

    /** @return list<string> */
    public function suffixTokens(string $family): array
    {
        return $this->suffixTokens[$family] ?? [];
    }

    private function claimKey(string $key, IndexedProduct|IndexedVariant|IndexedSpelling $record): void
    {
        if (isset($this->byKey[$key])) {
            throw new InvalidDesignationIndex('duplicate_key', "key \"{$key}\" is used more than once");
        }
        $this->byKey[$key] = $record;
    }

    /** @param array<string, true> $seen */
    private static function claimId(array &$seen, string $id): void
    {
        if (isset($seen[$id])) {
            throw new InvalidDesignationIndex('duplicate_id', "id \"{$id}\" is used more than once");
        }
        $seen[$id] = true;
    }

    private static function designationKey(string $text): string
    {
        $key = Normaliser::key($text);
        if ($key === '') {
            throw new InvalidDesignationIndex('empty_key', "\"{$text}\" normalises to nothing");
        }

        return $key;
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $value, string $where): array
    {
        if (!\is_array($value)) {
            throw new InvalidDesignationIndex('invalid_index', "{$where} must be an object");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    private static function list(array $data, string $field): array
    {
        $value = $data[$field] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw new InvalidDesignationIndex('invalid_index', "{$field} must be a list");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function string(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value)) {
            throw new InvalidDesignationIndex('invalid_index', "{$field} must be a string");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function bool(array $data, string $field): bool
    {
        $value = $data[$field] ?? null;
        if (!\is_bool($value)) {
            throw new InvalidDesignationIndex('invalid_index', "{$field} must be true or false");
        }

        return $value;
    }
}
