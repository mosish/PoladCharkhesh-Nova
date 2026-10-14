<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Schema;

use Nova\Core\Sql;
use Nova\Tests\Integration\TestDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Schema v1 enforces the reviewed integrity rules in the database itself
 * (schema review 2026-10-06). Each violation must fail with the named
 * constraint, so a test cannot pass for the wrong reason.
 */
final class SchemaV1Test extends TestCase
{
    private PDO $pdo;
    private int $family;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::migrated();
        $this->family = $this->insert('family', ['slug' => 'ball', 'name_fa' => 'ساچمه‌ای', 'name_en' => 'Ball', 'short_fa' => 'ساچمه‌ای', 'short_en' => 'Ball', 'definition_fa' => '-', 'definition_en' => '-']);
    }

    /** @param array<string, scalar|null> $row */
    private function insert(string $table, array $row): int
    {
        return TestDatabase::insert($this->pdo, $table, $row);
    }

    private function product(string $designation, string $slug): int
    {
        return $this->insert('product', ['family_id' => $this->family, 'designation' => $designation, 'slug' => $slug]);
    }

    private function variant(int $product, string $designation, string $slug): int
    {
        return $this->insert('product_variant', ['product_id' => $product, 'designation' => $designation, 'slug' => $slug]);
    }

    private function source(): int
    {
        return $this->insert('source', ['title' => 'Test source', 'url' => 'https://example.test/catalogue']);
    }

    private function specKey(string $code = 'd'): int
    {
        return $this->insert('spec_key', ['family_id' => $this->family, 'code' => $code, 'label_fa' => 'قطر', 'label_en' => 'Bore', 'unit' => 'mm', 'value_type' => 'number']);
    }

    /** @param callable(): mixed $action */
    private function assertViolates(string $constraint, callable $action): void
    {
        try {
            $action();
            self::fail("Expected a violation of {$constraint}");
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode(), $e->getMessage());
            self::assertStringContainsString($constraint, $e->getMessage());
        }
    }

    private function rows(string $table): int
    {
        return (int) Sql::scalar($this->pdo, "SELECT COUNT(*) FROM {$table}");
    }

    // --- engine, collation, conservative subset

    public function testEveryTableIsInnoDbWithTheProjectCollation(): void
    {
        $rows = Sql::rows($this->pdo, 'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $tables = array_map(static fn(array $r): string => (string) $r['TABLE_NAME'], $rows);
        sort($tables);
        self::assertSame(['app_user', 'audit_event', 'brand', 'designation_key', 'document', 'document_link', 'family', 'inquiry', 'inquiry_attempt', 'inquiry_item', 'login_throttle', 'product', 'product_variant', 'publish_change', 'publish_record', 'redirect', 'schema_migrations', 'setting', 'source', 'spec_key', 'spec_value', 'spelling'], $tables);
        foreach ($rows as $row) {
            self::assertSame('InnoDB', $row['ENGINE'], (string) $row['TABLE_NAME']);
            self::assertSame('utf8mb4_unicode_520_ci', $row['TABLE_COLLATION'], (string) $row['TABLE_NAME']);
        }
    }

    public function testKeysAndSlugsCompareExactly(): void
    {
        $rows = Sql::pairs($this->pdo, "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) c, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (COLUMN_NAME IN ('slug', 'norm_key'))");
        $columns = array_map(strval(...), array_keys($rows));
        sort($columns);
        self::assertSame(['brand.slug', 'designation_key.norm_key', 'family.slug', 'product.slug', 'product_variant.slug'], $columns);
        foreach ($rows as $column => $collation) {
            self::assertSame('utf8mb4_nopad_bin', $collation, (string) $column);
        }
    }

    public function testNoTriggersRoutinesOrEvents(): void
    {
        foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $schema) {
            self::assertSame(0, (int) Sql::scalar($this->pdo, "SELECT COUNT(*) FROM information_schema.{$table} WHERE {$schema} = DATABASE()"), $table);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function foreignKeys(): iterable
    {
        $cascade = ['product_variant.product_id', 'spelling.product_id', 'spelling.variant_id', 'designation_key.product_id', 'designation_key.variant_id', 'designation_key.spelling_id', 'spec_value.product_id', 'spec_value.variant_id', 'document_link.product_id', 'document_link.variant_id'];
        $restrict = ['product_variant.relationship_source_id', 'product_variant.relationship_confirmed_by', 'spelling.confirmed_by', 'redirect.created_by', 'publish_record.published_by', 'publish_change.audit_event_id', 'publish_change.publish_record_id', 'inquiry_item.inquiry_id', 'product.family_id', 'product.verified_by', 'product_variant.verified_by', 'spec_key.family_id', 'spec_value.spec_key_id', 'spec_value.source_id', 'spec_value.updated_by', 'spelling.source_id', 'source.document_id', 'document_link.document_id', 'audit_event.user_id', 'setting.updated_by'];
        foreach ($cascade as $c) {
            [$table, $column] = explode('.', $c, 2) + ['', ''];
            yield $c => [$table, $column, 'CASCADE'];
        }
        foreach ($restrict as $c) {
            [$table, $column] = explode('.', $c, 2) + ['', ''];
            yield $c => [$table, $column, 'RESTRICT'];
        }
    }

    #[DataProvider('foreignKeys')]
    public function testForeignKeyDeleteRule(string $table, string $column, string $rule): void
    {
        $statement = $this->pdo->prepare('SELECT rc.DELETE_RULE, rc.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ?');
        $statement->execute([$table, $column]);
        self::assertSame(['DELETE_RULE' => $rule, 'UPDATE_RULE' => 'RESTRICT'], $statement->fetch());
    }

    public function testEveryForeignKeyIsCovered(): void
    {
        $total = (int) Sql::scalar($this->pdo, "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()");
        self::assertSame(iterator_count(self::foreignKeys()), $total);
    }

    // --- designation_key: one namespace

    public function testOneKeyNamespaceAcrossProductsVariantsAndSpellings(): void
    {
        $p = $this->product('6205', '6205');
        $v = $this->variant($p, '6205-2RS', '6205-2rs');
        $s = $this->insert('spelling', ['product_id' => $p, 'spelling' => 'QZ SPELLING']);
        $this->insert('designation_key', ['norm_key' => '6205', 'product_id' => $p]);
        $this->insert('designation_key', ['norm_key' => '62052rs', 'variant_id' => $v]);
        $this->insert('designation_key', ['norm_key' => 'qzspelling', 'spelling_id' => $s]);

        $p2 = $this->product('6206', '6206');
        $this->assertViolates('uq_designation_key_norm', fn() => $this->insert('designation_key', ['norm_key' => '62052rs', 'product_id' => $p2]));
        $this->assertViolates('uq_designation_key_norm', fn() => $this->insert('designation_key', ['norm_key' => '6205', 'spelling_id' => $s]));
    }

    public function testKeyComparisonIsExactNotCollationFolded(): void
    {
        $a = $this->product('A1', 'a1');
        $b = $this->product('B1', 'b1');
        $c = $this->product('C1', 'c1');
        $this->insert('designation_key', ['norm_key' => 'qz6', 'product_id' => $a]);
        // A case-insensitive or accent/width-folding collation would call these duplicates.
        $this->insert('designation_key', ['norm_key' => 'QZ6', 'product_id' => $b]);
        $this->insert('designation_key', ['norm_key' => 'qz６', 'product_id' => $c]);
        self::assertSame(3, $this->rows('designation_key'));
    }

    public function testEachKeyRowHasExactlyOneOwnerAndEachOwnerOneKey(): void
    {
        $p = $this->product('6205', '6205');
        $v = $this->variant($p, '6205-2RS', '6205-2rs');
        $this->assertViolates('ck_designation_key_one_owner', fn() => $this->insert('designation_key', ['norm_key' => 'x1', 'product_id' => $p, 'variant_id' => $v]));
        $this->assertViolates('ck_designation_key_one_owner', fn() => $this->insert('designation_key', ['norm_key' => 'x2']));
        $this->insert('designation_key', ['norm_key' => '6205', 'product_id' => $p]);
        $this->assertViolates('uq_designation_key_owner', fn() => $this->insert('designation_key', ['norm_key' => 'x3', 'product_id' => $p]));
        $this->assertViolates('ck_designation_key_not_empty', fn() => $this->insert('designation_key', ['norm_key' => '', 'variant_id' => $v]));
    }

    // --- spelling targets exactly one Product or variant

    public function testSpellingTargetsExactlyOneProductOrVariant(): void
    {
        $p = $this->product('6205', '6205');
        $v = $this->variant($p, '6205-2RS', '6205-2rs');
        $this->assertViolates('ck_spelling_one_target', fn() => $this->insert('spelling', ['product_id' => $p, 'variant_id' => $v, 'spelling' => 'x']));
        $this->assertViolates('ck_spelling_one_target', fn() => $this->insert('spelling', ['spelling' => 'x']));
        $this->assertViolates('ck_spelling_brand_sourced', fn() => $this->insert('spelling', ['variant_id' => $v, 'spelling' => 'x', 'equivalence' => 'brand']));
        $this->insert('spelling', ['variant_id' => $v, 'spelling' => 'x', 'equivalence' => 'brand', 'source_id' => $this->source()]);
        self::assertSame(1, $this->rows('spelling'));
    }

    // --- spec values: one designation, per-value provenance, no NULL uniqueness

    public function testValueBelongsToExactlyOneDesignation(): void
    {
        $p = $this->product('6205', '6205');
        $v = $this->variant($p, '6205-2RS', '6205-2rs');
        $k = $this->specKey();
        $this->assertViolates('ck_spec_value_one_designation', fn() => $this->insert('spec_value', ['product_id' => $p, 'variant_id' => $v, 'spec_key_id' => $k, 'value_text' => '1']));
        $this->assertViolates('ck_spec_value_one_designation', fn() => $this->insert('spec_value', ['spec_key_id' => $k, 'value_text' => '1']));
    }

    public function testBaseAndVariantValuesAreSeparateAndEachUnique(): void
    {
        $p = $this->product('6205', '6205');
        $v = $this->variant($p, '6205-2RS', '6205-2rs');
        $k = $this->specKey();
        $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $k, 'value_text' => 'base value']);
        $this->insert('spec_value', ['variant_id' => $v, 'spec_key_id' => $k, 'value_text' => 'variant value']);
        $this->assertViolates('uq_spec_value_designation_key', fn() => $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $k, 'value_text' => 'again']));
        $this->assertViolates('uq_spec_value_designation_key', fn() => $this->insert('spec_value', ['variant_id' => $v, 'spec_key_id' => $k, 'value_text' => 'again']));
        $refs = Sql::column($this->pdo, 'SELECT designation_ref FROM spec_value ORDER BY id');
        self::assertSame(["p:{$p}", "v:{$v}"], $refs);
    }

    public function testVerifiedValueRequiresSourceAndCheckedDate(): void
    {
        $p = $this->product('6205', '6205');
        $k = $this->specKey();
        $source = $this->source();
        $this->assertViolates('ck_spec_value_verified_sourced', fn() => $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $k, 'value_text' => '1', 'status' => 'verified']));
        $this->assertViolates('ck_spec_value_verified_sourced', fn() => $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $k, 'value_text' => '1', 'status' => 'verified', 'source_id' => $source]));
        $this->assertViolates('ck_spec_value_verified_sourced', fn() => $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $k, 'value_text' => '1', 'status' => 'verified', 'checked_on' => '2026-10-06']));
        $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $k, 'value_text' => '1', 'status' => 'verified', 'source_id' => $source, 'checked_on' => '2026-10-06']);
        $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $this->specKey('D'), 'value_text' => '2']);
        self::assertSame(2, $this->rows('spec_value'), 'pending values need no source');
    }

    public function testSourceMustBeCitable(): void
    {
        $this->assertViolates('ck_source_citable', fn() => $this->insert('source', ['title' => 'Nothing to cite']));
        $this->assertViolates('ck_source_url', fn() => $this->insert('source', ['title' => 'x', 'url' => 'javascript:alert(1)']));
    }

    // --- Product / ProductVariant

    public function testProductDefaultsAndVariantCardinality(): void
    {
        $p = $this->product('6305', '6305');
        $row = Sql::row($this->pdo, "SELECT base_listed, status, verification, relationship_review, is_sample, row_version FROM product WHERE id = {$p}");
        self::assertSame(['base_listed' => 0, 'status' => 'draft', 'verification' => 'pending', 'relationship_review' => 0, 'is_sample' => 0, 'row_version' => 1], $row);

        self::assertSame(0, $this->rows('product_variant'), 'zero variants is valid');
        $this->variant($p, '6305-2RS', '6305-2rs');
        $this->variant($p, '6305-ZZ', '6305-zz');
        $listed = Sql::column($this->pdo, "SELECT listed FROM product_variant WHERE product_id = {$p}");
        self::assertSame([0, 0], $listed, 'variants are unlisted until listed explicitly');
    }

    public function testVerificationNeedsWhoAndWhen(): void
    {
        $this->assertViolates('ck_product_verified', fn() => $this->insert('product', ['family_id' => $this->family, 'designation' => '1', 'slug' => 'p1', 'verification' => 'verified']));
        $this->assertViolates('ck_product_slug', fn() => $this->insert('product', ['family_id' => $this->family, 'designation' => '1', 'slug' => 'Has Space']));
        $this->assertViolates('ck_product_flags', fn() => $this->insert('product', ['family_id' => $this->family, 'designation' => '1', 'slug' => 'p2', 'base_listed' => 2]));
        $this->assertViolates('uq_product_slug', function (): void {
            $this->product('A', 'same');
            $this->product('B', 'same');
        });
    }

    // --- 0002: relationship evidence and redirects

    public function testVariantRelationshipConfirmationNeedsOwnerAndSource(): void
    {
        $p = $this->product('6305', '6305');
        $user = $this->insert('app_user', ['email' => 'o@test.invalid', 'display_name' => 'o', 'role' => 'owner', 'password_hash' => 'x']);
        $this->assertViolates('ck_product_variant_relationship', fn() => $this->insert('product_variant', ['product_id' => $p, 'designation' => 'x', 'slug' => 'x', 'relationship_confirmed_at' => '2026-10-06 00:00:00', 'relationship_confirmed_by' => $user]));
        $this->insert('product_variant', ['product_id' => $p, 'designation' => 'y', 'slug' => 'y', 'relationship_confirmed_at' => '2026-10-06 00:00:00', 'relationship_confirmed_by' => $user, 'relationship_source_id' => $this->source()]);
        $this->assertViolates('ck_spelling_confirmed', fn() => $this->insert('spelling', ['product_id' => $p, 'spelling' => 'z', 'confirmed_at' => '2026-10-06 00:00:00']));
    }

    public function testRedirectShape(): void
    {
        $this->assertViolates('ck_redirect_status', fn() => $this->insert('redirect', ['from_path' => '/p/a/', 'status' => 301, 'reason' => 'x']));
        $this->assertViolates('ck_redirect_status', fn() => $this->insert('redirect', ['from_path' => '/p/a/', 'to_path' => '/p/b/', 'status' => 410, 'reason' => 'x']));
        $this->assertViolates('ck_redirect_status', fn() => $this->insert('redirect', ['from_path' => '/p/a/', 'to_path' => '/p/b/', 'status' => 302, 'reason' => 'x']));
        $this->assertViolates('ck_redirect_paths', fn() => $this->insert('redirect', ['from_path' => 'https://evil.test/', 'status' => 410, 'reason' => 'x']));
        $this->insert('redirect', ['from_path' => '/p/a/', 'to_path' => '/p/b/#v-a', 'status' => 301, 'reason' => 'x']);
        $this->assertViolates('uq_redirect_from', fn() => $this->insert('redirect', ['from_path' => '/p/a/', 'status' => 410, 'reason' => 'x']));
    }

    // --- delete behaviour

    public function testDeletingADraftProductRemovesEverythingItOwns(): void
    {
        $p = $this->product('6205', '6205');
        $v = $this->variant($p, '6205-2RS', '6205-2rs');
        $s = $this->insert('spelling', ['variant_id' => $v, 'spelling' => 'x']);
        $this->insert('designation_key', ['norm_key' => '6205', 'product_id' => $p]);
        $this->insert('designation_key', ['norm_key' => '62052rs', 'variant_id' => $v]);
        $this->insert('designation_key', ['norm_key' => 'x', 'spelling_id' => $s]);
        $k = $this->specKey();
        $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $k, 'value_text' => '1']);
        $this->insert('spec_value', ['variant_id' => $v, 'spec_key_id' => $k, 'value_text' => '1']);
        $doc = $this->insert('document', ['sha256' => str_repeat('a', 64), 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'byte_size' => 1]);
        $this->insert('document_link', ['document_id' => $doc, 'variant_id' => $v, 'language' => 'both']);

        $this->pdo->exec("DELETE FROM product WHERE id = {$p}");
        foreach (['product_variant', 'spelling', 'designation_key', 'spec_value', 'document_link'] as $table) {
            self::assertSame(0, $this->rows($table), $table);
        }
        self::assertSame(1, $this->rows('document'), 'shared documents survive');
        self::assertSame(1, $this->rows('spec_key'), 'shared spec keys survive');
    }

    public function testSharedRecordsCannotBeDeletedWhileUsed(): void
    {
        $p = $this->product('6205', '6205');
        $k = $this->specKey();
        $source = $this->source();
        $this->insert('spec_value', ['product_id' => $p, 'spec_key_id' => $k, 'value_text' => '1', 'source_id' => $source]);

        $this->assertViolates('fk_product_family', fn() => $this->pdo->exec("DELETE FROM family WHERE id = {$this->family}"));
        $this->assertViolates('fk_spec_value_key', fn() => $this->pdo->exec("DELETE FROM spec_key WHERE id = {$k}"));
        $this->assertViolates('fk_spec_value_source', fn() => $this->pdo->exec("DELETE FROM source WHERE id = {$source}"));
    }
}
