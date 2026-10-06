<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Catalogue;

use Nova\Catalogue\CatalogueError;
use Nova\Catalogue\RelationshipReview;
use Nova\Core\Sql;
use Nova\Provenance\DesignationRef;
use Nova\Provenance\ValueInput;
use Nova\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Hypothetical relationships for test purposes: "QZ 1" / "QZ 1 E" are invented
 * designations, and nothing here asserts a real bearing relationship.
 */
final class RelationshipReviewTest extends TestCase
{
    private CatalogueFixture $f;
    private RelationshipReview $review;

    protected function setUp(): void
    {
        $this->f = new CatalogueFixture();
        $this->review = new RelationshipReview($this->f->pdo, $this->f->audit);
    }

    public function testResolvingMovesEverythingOwnedByTheDesignationAndRedirectsItsUrl(): void
    {
        $target = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        $old = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1 E', relationshipReview: true);
        Sql::run($this->f->pdo, 'UPDATE product SET base_listed = 1 WHERE id = ?', [$old]);
        $this->f->values->setValue($this->f->editor, DesignationRef::base($old), $this->f->keyD, new ValueInput('100'));
        $this->f->products->addSpelling($this->f->owner, $old, null, 'QZ1-E-ALT');
        $doc = TestDatabase::insert($this->f->pdo, 'document', ['sha256' => str_repeat('b', 64), 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'byte_size' => 1]);
        TestDatabase::insert($this->f->pdo, 'document_link', ['document_id' => $doc, 'product_id' => $old, 'language' => 'both']);
        $this->f->markPublishedOnce($old);

        $variant = $this->review->resolveAsVariant($this->f->owner, $old, $target, $this->f->source, 'Owner confirmed from the cited catalogue');

        self::assertNull(Sql::row($this->f->pdo, 'SELECT id FROM product WHERE id = ?', [$old]));
        $row = $this->f->products->variant($variant);
        self::assertSame(['product_id' => $target, 'designation' => 'QZ 1 E', 'slug' => 'qz-1-e', 'listed' => 1], array_intersect_key($row, ['product_id' => 0, 'designation' => 0, 'slug' => 0, 'listed' => 0]));
        self::assertNotNull($row['relationship_confirmed_at']);
        self::assertSame('qz1e', $this->f->key('variant', $variant));
        self::assertSame(1, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM spec_value WHERE variant_id = ? AND product_id IS NULL', [$variant]), 'the value moved with its designation');
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM spec_value WHERE product_id = ?', [$target]), 'nothing was copied to the target base');
        self::assertSame(1, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM spelling WHERE variant_id = ?', [$variant]));
        self::assertSame(1, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM document_link WHERE variant_id = ?', [$variant]));
        self::assertSame(['from_path' => '/p/qz-1-e/', 'to_path' => '/p/qz-1/#v-qz-1-e', 'status' => 301], Sql::row($this->f->pdo, 'SELECT from_path, to_path, status FROM redirect'));
        self::assertContains('relationship.resolve_variant', $this->f->auditActions());
    }

    public function testNeverPublishedDesignationNeedsNoRedirect(): void
    {
        $target = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        $old = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1 E', relationshipReview: true);
        $this->review->resolveAsVariant($this->f->owner, $old, $target, $this->f->source, 'note');
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM redirect'));
    }

    public function testRefusals(): void
    {
        $target = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        $old = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1 E');
        $other = TestDatabase::insert($this->f->pdo, 'product', ['family_id' => $this->f->otherFamily, 'designation' => 'QZ 9', 'slug' => 'qz-9']);
        $withVariants = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 2');
        $this->f->products->addVariant($this->f->owner, $withVariants, 'QZ 2 K', $this->f->source);

        $cases = [
            'forbidden' => fn() => $this->review->resolveAsVariant($this->f->editor, $old, $target, $this->f->source, 'x'),
            'invalid' => fn() => $this->review->resolveAsVariant($this->f->owner, $target, $target, $this->f->source, 'x'),
            'not_found' => fn() => $this->review->resolveAsVariant($this->f->owner, $old, $target, 999999, 'x'),
        ];
        foreach ($cases as $reason => $action) {
            try {
                $action();
                self::fail("expected {$reason}");
            } catch (CatalogueError $e) {
                self::assertSame($reason, $e->reason);
            }
        }
        foreach ([[$other, $target], [$withVariants, $target]] as [$from, $to]) {
            try {
                $this->review->resolveAsVariant($this->f->owner, $from, $to, $this->f->source, 'x');
                self::fail('expected a conflict');
            } catch (CatalogueError $e) {
                self::assertSame('conflict', $e->reason);
            }
        }
    }

    public function testConfirmingAStandaloneDesignationClosesTheReview(): void
    {
        $id = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1 E', relationshipReview: true);
        $this->review->confirmStandalone($this->f->owner, $id, 'Its own designation per the cited catalogue');
        self::assertSame(0, $this->f->products->product($id)['relationship_review']);
    }
}
