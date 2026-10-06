<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Catalogue;

use Nova\Catalogue\CatalogueError;
use Nova\Catalogue\ProductChanges;
use Nova\Catalogue\VariantChanges;
use Nova\Core\Sql;
use Nova\Provenance\DesignationRef;
use Nova\Provenance\ValueInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductServiceTest extends TestCase
{
    private CatalogueFixture $f;

    protected function setUp(): void
    {
        $this->f = new CatalogueFixture();
    }

    /** @param callable(): mixed $action */
    private function assertRefused(string $reason, callable $action, string $messagePart = ''): void
    {
        try {
            $action();
            self::fail("Expected a refusal ({$reason})");
        } catch (CatalogueError $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
            self::assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    public function testCreatingAProductClaimsItsKeyAndStartsAsAnUnlistedDraft(): void
    {
        $id = $this->f->products->createProduct($this->f->editor, $this->f->family, '  NU 205 ');
        $row = $this->f->products->product($id);
        self::assertSame('NU 205', $row['designation']);
        self::assertSame('nu-205', $row['slug']);
        self::assertSame(0, $row['base_listed']);
        self::assertSame('draft', $row['status']);
        self::assertSame('nu205', $this->f->key('product', $id));
        self::assertSame(['product.create'], $this->f->auditActions());
    }

    public function testOneNamespaceRefusesCollisionsAcrossProductsVariantsAndSpellings(): void
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, '6205');
        $v = $this->f->products->addVariant($this->f->owner, $p, '6205-2RS', $this->f->source);
        $this->f->products->addSpelling($this->f->owner, null, $v, 'QZ ALT WRITING');

        $this->assertRefused('designation_collision', fn() => $this->f->products->createProduct($this->f->editor, $this->f->family, '6205 2rs', 'other'), '"6205-2RS"');
        $this->assertRefused('designation_collision', fn() => $this->f->products->createProduct($this->f->editor, $this->f->family, '６２０５', 'other2'), '"6205"');
        $this->assertRefused('designation_collision', fn() => $this->f->products->addVariant($this->f->owner, $p, 'qz-alt-writing', $this->f->source), '"QZ ALT WRITING"');
        $this->assertRefused('designation_collision', fn() => $this->f->products->addVariant($this->f->owner, $p, '6205', $this->f->source, false, 'x6205'), '"6205"');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDesignations(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \u{00A0} "];
        yield 'separators only' => ['- . _'];
        yield 'over 64 code points' => [str_repeat('۶', 65)];
    }

    #[DataProvider('invalidDesignations')]
    public function testInvalidDesignationsAreRefused(string $designation): void
    {
        $this->assertRefused('invalid', fn() => $this->f->products->createProduct($this->f->editor, $this->f->family, $designation, 'slug-x'));
    }

    public function testChangingTheDesignationRekeysAndFreesTheOldKey(): void
    {
        $id = $this->f->products->createProduct($this->f->editor, $this->f->family, '6205');
        $this->f->products->updateProduct($this->f->editor, $id, 1, new ProductChanges(designation: '6205 A'));
        self::assertSame('6205a', $this->f->key('product', $id));
        $this->f->products->createProduct($this->f->editor, $this->f->family, '6205', 'fresh-6205');
        self::assertSame(2, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM designation_key'));
    }

    public function testStaleWritesAreRefused(): void
    {
        $id = $this->f->products->createProduct($this->f->editor, $this->f->family, '6205');
        $this->f->products->updateProduct($this->f->editor, $id, 1, new ProductChanges(baseListed: true));
        $this->assertRefused('stale', fn() => $this->f->products->updateProduct($this->f->editor, $id, 1, new ProductChanges(baseListed: false)));
        self::assertSame(1, $this->f->products->product($id)['base_listed']);
    }

    public function testSlugIsFrozenOnceTheProductHasBeenPublished(): void
    {
        $id = $this->f->products->createProduct($this->f->editor, $this->f->family, '6205');
        $this->f->products->updateProduct($this->f->editor, $id, 1, new ProductChanges(slug: 'six-two-o-five'));
        $this->f->markPublishedOnce($id);
        $this->assertRefused('conflict', fn() => $this->f->products->updateProduct($this->f->editor, $id, 2, new ProductChanges(slug: 'another')), 'published URL');
    }

    public function testFamilyCannotChangeWhileValuesExist(): void
    {
        $id = $this->f->products->createProduct($this->f->editor, $this->f->family, '6205');
        $this->f->values->setValue($this->f->editor, DesignationRef::base($id), $this->f->keyD, new ValueInput('25'));
        $this->assertRefused('conflict', fn() => $this->f->products->updateProduct($this->f->editor, $id, 1, new ProductChanges(familyId: $this->f->otherFamily)));
    }

    public function testOnlyTheOwnerRecordsAVariantAndAlwaysWithASource(): void
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, '6305');
        $this->assertRefused('forbidden', fn() => $this->f->products->addVariant($this->f->editor, $p, '6305-2RS', $this->f->source));
        $this->assertRefused('not_found', fn() => $this->f->products->addVariant($this->f->owner, $p, '6305-2RS', 999999));

        $v = $this->f->products->addVariant($this->f->owner, $p, '6305-2RS', $this->f->source, true);
        $variant = $this->f->products->variant($v);
        self::assertSame($this->f->owner->userId, $variant['relationship_confirmed_by']);
        self::assertSame($this->f->source, $variant['relationship_source_id']);
        self::assertSame(1, $variant['listed']);
        self::assertSame(0, $this->f->products->product($p)['base_listed'], 'listing a variant never lists the plain designation');
    }

    public function testVariantListingAndStatusChanges(): void
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, '6305');
        $v = $this->f->products->addVariant($this->f->owner, $p, '6305-ZZ', $this->f->source);
        $this->f->products->updateVariant($this->f->editor, $v, 1, new VariantChanges(listed: true, status: 'published'));
        self::assertSame(['listed' => 1, 'status' => 'published'], array_intersect_key($this->f->products->variant($v), ['listed' => 0, 'status' => 0]));
        $this->assertRefused('invalid', fn() => $this->f->products->updateVariant($this->f->editor, $v, 2, new VariantChanges(status: 'live')));
    }

    public function testArchivingAPublishedProductMakesItsUrlGoneAndRepublishingRestoresIt(): void
    {
        $draft = $this->f->products->createProduct($this->f->editor, $this->f->family, '6200');
        $this->f->products->setProductStatus($this->f->editor, $draft, 1, 'archived');
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM redirect'), 'a never-published page leaves no trace');

        $id = $this->f->products->createProduct($this->f->editor, $this->f->family, '6205');
        $this->f->markPublishedOnce($id);
        $this->f->products->setProductStatus($this->f->editor, $id, 1, 'archived');
        self::assertSame(['from_path' => '/p/6205/', 'to_path' => null, 'status' => 410], Sql::row($this->f->pdo, 'SELECT from_path, to_path, status FROM redirect'));
        $this->f->products->setProductStatus($this->f->editor, $id, 2, 'published');
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM redirect'));
    }

    public function testPublishedProductsAreArchivedNeverDeleted(): void
    {
        $id = $this->f->products->createProduct($this->f->editor, $this->f->family, '6205');
        $this->f->markPublishedOnce($id);
        $this->assertRefused('conflict', fn() => $this->f->products->deleteDraftProduct($this->f->editor, $id));

        $draft = $this->f->products->createProduct($this->f->editor, $this->f->family, '6206');
        $this->f->products->deleteDraftProduct($this->f->editor, $draft);
        self::assertNull($this->f->key('product', $draft));
    }

    public function testSpellingRules(): void
    {
        $p = $this->f->products->createProduct($this->f->owner, $this->f->family, '22220 E');
        $this->assertRefused('forbidden', fn() => $this->f->products->addSpelling($this->f->editor, $p, null, 'ALT'));
        $this->assertRefused('invalid', fn() => $this->f->products->addSpelling($this->f->owner, $p, null, 'ALT', 'brand'), 'source');
        $this->assertRefused('invalid', fn() => $this->f->products->addSpelling($this->f->owner, null, null, 'ALT'));
        $this->assertRefused('designation_collision', fn() => $this->f->products->addSpelling($this->f->owner, $p, null, '22220E'), 'same designation key');

        $s = $this->f->products->addSpelling($this->f->owner, $p, null, 'ALT 22220', 'brand', $this->f->source);
        self::assertSame('alt22220', $this->f->key('spelling', $s));
        $this->f->products->removeSpelling($this->f->owner, $s);
        self::assertNull($this->f->key('spelling', $s));
    }
}
