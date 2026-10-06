<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Catalogue;

use Nova\Catalogue\PublishedIndex;
use Nova\Core\Environment;
use Nova\Core\Sql;
use Nova\Designation\DesignationIndex;
use Nova\Designation\Matcher;
use Nova\Dev\SampleCatalogue;
use PHPUnit\Framework\TestCase;

final class PublishedIndexTest extends TestCase
{
    public function testTheSampleCatalogueInTheDatabaseMatchesLikeTheCorpusSample(): void
    {
        $f = new CatalogueFixture();
        Sql::run($f->pdo, 'DELETE FROM spec_key');
        Sql::run($f->pdo, 'DELETE FROM family');
        SampleCatalogue::load($f->pdo, Environment::Development);
        $matcher = new Matcher(DesignationIndex::fromArray(PublishedIndex::build($f->pdo, true)));

        $json = (string) file_get_contents(\dirname(__DIR__, 2) . '/fixtures/designation/corpus.json');
        /** @var array{match: list<array{catalogue: string, input: string, expect: array<string, mixed>}>} $corpus */
        $corpus = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $checked = 0;
        foreach ($corpus['match'] as $case) {
            if ($case['catalogue'] !== 'sample-as-recorded') {
                continue;
            }
            $got = $matcher->match($case['input'])->toArray();
            foreach (['type', 'via', 'designation', 'listed', 'suffix', 'reason', 'requested', 'key'] as $field) {
                self::assertSame($case['expect'][$field], $got[$field], "{$case['input']}: {$field}");
            }
            ++$checked;
        }
        self::assertGreaterThan(30, $checked);
    }

    public function testProductionIndexExcludesSamples(): void
    {
        $f = new CatalogueFixture();
        Sql::run($f->pdo, 'DELETE FROM spec_key');
        Sql::run($f->pdo, 'DELETE FROM family');
        SampleCatalogue::load($f->pdo, Environment::Development);
        self::assertSame([], PublishedIndex::build($f->pdo, false)['products']);
    }

    public function testOnlyPublishedRecordsAndConfirmedRelationshipsAreIndexed(): void
    {
        $f = new CatalogueFixture();
        $p = $f->products->createProduct($f->owner, $f->family, 'QZ 1');
        $f->products->createProduct($f->owner, $f->family, 'QZ DRAFT');
        $confirmed = $f->products->addVariant($f->owner, $p, 'QZ 1 K', $f->source, true);
        Sql::run($f->pdo, "UPDATE product SET status = 'published' WHERE id = ?", [$p]);
        Sql::run($f->pdo, "UPDATE product_variant SET status = 'published' WHERE id = ?", [$confirmed]);
        Sql::run($f->pdo, "INSERT INTO product_variant (product_id, designation, slug, listed, status) VALUES (?, 'QZ 1 U', 'qz-1-u', 1, 'published')", [$p]);
        $f->products->addSpelling($f->owner, $p, null, 'QZ ONE');
        Sql::run($f->pdo, "INSERT INTO spelling (product_id, spelling) VALUES (?, 'QZ UNCONFIRMED')", [$p]);

        $index = PublishedIndex::build($f->pdo, false);
        self::assertSame([['id' => 'p:qz-1', 'designation' => 'QZ 1', 'family' => 'test-family', 'base_listed' => false, 'variants' => [['id' => 'v:qz-1-k', 'designation' => 'QZ 1 K', 'listed' => true]]]], $index['products']);
        self::assertSame([['spelling' => 'QZ ONE', 'target' => 'p:qz-1']], $index['spellings']);
        self::assertSame([], $index['suffix_rules'], 'no suffix rules are approved (V-1)');
    }
}
