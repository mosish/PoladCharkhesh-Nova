<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Dev;

use Nova\Core\Environment;
use Nova\Core\Sql;
use Nova\Designation\Normaliser;
use Nova\Dev\SampleCatalogue;
use Nova\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SampleCatalogueTest extends TestCase
{
    public function testLoadsThePhase2SampleExactlyAsRecordedAndLabelled(): void
    {
        $pdo = TestDatabase::migrated();
        SampleCatalogue::load($pdo, Environment::Development);

        self::assertSame(10, (int) Sql::scalar($pdo, 'SELECT COUNT(*) FROM family'));
        self::assertSame(13, (int) Sql::scalar($pdo, 'SELECT COUNT(*) FROM product'));
        self::assertSame(10, (int) Sql::scalar($pdo, 'SELECT COUNT(*) FROM product_variant'));
        self::assertSame(0, (int) Sql::scalar($pdo, 'SELECT COUNT(*) FROM product WHERE is_sample <> 1'), 'every row is labelled as a sample');
        self::assertSame(37, (int) Sql::scalar($pdo, 'SELECT COUNT(*) FROM spec_value'), 'all 37 dimension values of the sample');
        self::assertSame(0, (int) Sql::scalar($pdo, 'SELECT COUNT(*) FROM spec_value WHERE variant_id IS NOT NULL'), 'no value copied to a variant');
        self::assertSame(0, (int) Sql::scalar($pdo, "SELECT COUNT(*) FROM spec_value WHERE status <> 'pending'"), 'sample values are never verified');
        self::assertSame(3, (int) Sql::scalar($pdo, "SELECT COUNT(*) FROM spec_value v JOIN product p ON p.id = v.product_id WHERE p.designation = 'TC 25×42×7' AND v.source_id IS NULL"), 'unsourced in the sample, unsourced here');
        self::assertSame(['B', 'D', 'd'], array_map(strval(...), Sql::column($pdo, "SELECT k.code FROM spec_key k JOIN family f ON f.id = k.family_id WHERE f.slug = 'ball' ORDER BY k.code")));

        $review = Sql::column($pdo, 'SELECT designation FROM product WHERE relationship_review = 1 ORDER BY designation');
        self::assertSame(['22220 E', '6305-2RS'], $review);
        self::assertSame(0, (int) Sql::scalar($pdo, "SELECT COUNT(*) FROM product WHERE designation IN ('6305', '22220')"), 'no relationship invented');

        // Every product and variant owns exactly one key, equal to the normaliser's output.
        $rows = Sql::rows($pdo, 'SELECT p.designation, k.norm_key FROM product p JOIN designation_key k ON k.product_id = p.id UNION ALL SELECT v.designation, k.norm_key FROM product_variant v JOIN designation_key k ON k.variant_id = v.id');
        self::assertCount(23, $rows);
        foreach ($rows as $row) {
            self::assertSame(Normaliser::key((string) $row['designation']), $row['norm_key']);
        }
    }

    public function testRefusedOutsideDevelopment(): void
    {
        $pdo = TestDatabase::migrated();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only in development');
        SampleCatalogue::load($pdo, Environment::Production);
    }

    public function testRefusedIntoANonEmptyCatalogue(): void
    {
        $pdo = TestDatabase::migrated();
        SampleCatalogue::load($pdo, Environment::Development);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not empty');
        SampleCatalogue::load($pdo, Environment::Development);
    }
}
