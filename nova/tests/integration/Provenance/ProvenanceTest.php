<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Provenance;

use Nova\Catalogue\CatalogueError;
use Nova\Core\Sql;
use Nova\Provenance\DesignationRef;
use Nova\Provenance\ValueInput;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use Nova\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Per-value provenance, no inheritance, E-9 resets and Owner-only page verification. Test data only. */
final class ProvenanceTest extends TestCase
{
    private CatalogueFixture $f;

    protected function setUp(): void
    {
        $this->f = new CatalogueFixture();
    }

    private function verified(string $value): ValueInput
    {
        return new ValueInput($value, 'verified', $this->f->source, '2026-10-01', 'p. 12');
    }

    /** @param callable(): mixed $action */
    private function refused(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail("expected {$reason}");
        } catch (CatalogueError $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    /** @return array{int, int} product id and listed variant id, page fully verifiable */
    private function verifiablePage(): array
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 100');
        Sql::run($this->f->pdo, 'UPDATE product SET base_listed = 1 WHERE id = ?', [$p]);
        $v = $this->f->products->addVariant($this->f->owner, $p, 'QZ 100 K', $this->f->source, true);
        foreach ([DesignationRef::base($p), DesignationRef::variant($v)] as $ref) {
            $this->f->values->setValue($this->f->editor, $ref, $this->f->keyD, $this->verified('10'));
            $this->f->values->setValue($this->f->editor, $ref, $this->f->keyBigD, $this->verified('30'));
        }

        return [$p, $v];
    }

    public function testBaseAndVariantValuesAreIndependentNothingInherited(): void
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 100');
        $v = $this->f->products->addVariant($this->f->owner, $p, 'QZ 100 K', $this->f->source);
        $this->f->values->setValue($this->f->editor, DesignationRef::base($p), $this->f->keyD, new ValueInput('25'));

        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM spec_value WHERE variant_id = ?', [$v]), 'the variant has no value until one is sourced for it');
        $this->f->values->setValue($this->f->editor, DesignationRef::variant($v), $this->f->keyD, new ValueInput('25.5'));
        self::assertSame(['25', '25.5'], array_map(strval(...), Sql::column($this->f->pdo, 'SELECT value_text FROM spec_value ORDER BY id')));
    }

    public function testValueValidation(): void
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 100');
        $other = TestDatabase::insert($this->f->pdo, 'spec_key', ['family_id' => $this->f->otherFamily, 'code' => 'x', 'label_fa' => 'x', 'label_en' => 'x', 'value_type' => 'number']);
        $base = DesignationRef::base($p);

        $this->refused('invalid', fn() => $this->f->values->setValue($this->f->editor, $base, $other, new ValueInput('1')));
        $this->refused('invalid', fn() => $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('about 25')));
        $this->refused('invalid', fn() => $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('25', 'verified')));
        $this->refused('invalid', fn() => $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('25', 'verified', $this->f->source)));
        $this->refused('invalid', fn() => $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('25', 'verified', $this->f->source, '2999-01-01')));
        $this->refused('invalid', fn() => $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('25', 'pending', null, '2026-02-30')));

        $id = $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('۲۵٫۵'));
        self::assertSame(['value_text' => '25.5', 'value_num' => '25.500000', 'unit' => 'mm'], Sql::row($this->f->pdo, 'SELECT value_text, value_num, unit FROM spec_value WHERE id = ?', [$id]));
        $this->f->values->setValue($this->f->editor, $base, $this->f->keyNote, new ValueInput('free text, not a number'));
    }

    public function testUpdatesNeedTheCurrentVersionAndAreAudited(): void
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 100');
        $base = DesignationRef::base($p);
        $id = $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('25'));
        $this->refused('stale', fn() => $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('26')));
        $this->f->values->setValue($this->f->editor, $base, $this->f->keyD, new ValueInput('26'), 1);

        $audit = Sql::row($this->f->pdo, "SELECT before_text, after_text FROM audit_event WHERE action = 'value.update'");
        self::assertNotNull($audit);
        self::assertStringContainsString('"value_text":"25"', (string) $audit['before_text']);
        self::assertStringContainsString('"value_text":"26"', (string) $audit['after_text']);
        $this->f->values->removeValue($this->f->editor, $id, 2);
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM spec_value'));
    }

    public function testPageVerificationRequirements(): void
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 100', relationshipReview: true);
        $issues = $this->f->verification->issues($p);
        self::assertContains('no_listed_items: nothing on the page is listed', $issues);
        self::assertSame('relationship_review', explode(':', $issues[0])[0]);

        $empty = TestDatabase::insert($this->f->pdo, 'product', ['family_id' => $this->f->otherFamily, 'designation' => 'QZ 9', 'slug' => 'qz-9', 'base_listed' => 1]);
        self::assertContains('no_core_keys: the family has no core spec keys defined (V-6)', $this->f->verification->issues($empty));

        Sql::run($this->f->pdo, 'UPDATE product SET relationship_review = 0, base_listed = 1 WHERE id = ?', [$p]);
        $this->f->values->setValue($this->f->editor, DesignationRef::base($p), $this->f->keyD, new ValueInput('10'));
        self::assertSame(['unverified_value: QZ 100 d', 'missing_value: QZ 100 D'], $this->f->verification->issues($p));

        $unconfirmed = TestDatabase::insert($this->f->pdo, 'product_variant', ['product_id' => $p, 'designation' => 'QZ 100 Z', 'slug' => 'qz-100-z', 'listed' => 1]);
        self::assertContains('unconfirmed_variant: QZ 100 Z', $this->f->verification->issues($p));
        self::assertGreaterThan(0, $unconfirmed);
    }

    public function testOnlyTheOwnerVerifiesAndVerificationCoversEveryListedItem(): void
    {
        [$p, $v] = $this->verifiablePage();
        self::assertSame([], $this->f->verification->issues($p));
        $this->refused('forbidden', fn() => $this->f->verification->verify($this->f->editor, $p, $this->f->version('product', $p)));

        $this->f->verification->verify($this->f->owner, $p, $this->f->version('product', $p));
        self::assertSame('verified', $this->f->products->product($p)['verification']);
        self::assertSame('verified', $this->f->products->variant($v)['verification']);
    }

    public function testEditingACoreValueResetsVerificationButNonCoreAndNoOpEditsDoNot(): void
    {
        [$p, $v] = $this->verifiablePage();
        $this->f->verification->verify($this->f->owner, $p, $this->f->version('product', $p));

        $noteId = $this->f->values->setValue($this->f->editor, DesignationRef::base($p), $this->f->keyNote, new ValueInput('remark'));
        self::assertSame('verified', $this->f->products->product($p)['verification'], 'non-core values do not reset');
        self::assertGreaterThan(0, $noteId);

        $value = Sql::row($this->f->pdo, 'SELECT id, row_version FROM spec_value WHERE variant_id = ? AND spec_key_id = ?', [$v, $this->f->keyD]);
        self::assertNotNull($value);
        $this->f->values->setValue($this->f->editor, DesignationRef::variant($v), $this->f->keyD, $this->verified('10'), (int) $value['row_version']);
        self::assertSame('verified', $this->f->products->product($p)['verification'], 're-saving the same value is not a change');

        $this->f->values->setValue($this->f->editor, DesignationRef::variant($v), $this->f->keyD, $this->verified('11'), (int) $value['row_version'] + 1);
        self::assertSame('pending', $this->f->products->product($p)['verification']);
        self::assertSame('pending', $this->f->products->variant($v)['verification']);
        self::assertContains('product.verification_reset', $this->f->auditActions());
    }
}
