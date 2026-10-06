<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Migration;

use Nova\Core\Paths;
use Nova\Migration\MigrationFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every migration stays inside D12's conservative MariaDB subset and the table conventions. */
final class MigrationLintTest extends TestCase
{
    /** @return iterable<string, array{MigrationFile}> */
    public static function migrations(): iterable
    {
        foreach (MigrationFile::scan(Paths::fromHere()->migrations()) as $file) {
            yield basename($file->path) => [$file];
        }
    }

    #[DataProvider('migrations')]
    public function testUsesOnlyTheConservativeSubset(MigrationFile $file): void
    {
        $sql = implode("\n", $file->statements());
        $forbidden = [
            'stored programs' => '/\bCREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:TRIGGER|PROCEDURE|FUNCTION|EVENT)\b/i',
            'delimiter changes' => '/\bDELIMITER\b/i',
            'RETURNING' => '/\bRETURNING\b/i',
            'sequences' => '/\b(?:CREATE|ALTER)\s+SEQUENCE\b|\bNEXTVAL\s*\(/i',
            'JSON functions' => '/\bJSON_[A-Z_]+\s*\(/i',
            'system-versioned tables' => '/\bSYSTEM\s+VERSIONING\b/i',
            'partitioning' => '/\bPARTITION\s+BY\b/i',
        ];
        foreach ($forbidden as $what => $pattern) {
            self::assertDoesNotMatchRegularExpression($pattern, $sql, "{$what}: outside D12's subset");
        }
    }

    #[DataProvider('migrations')]
    public function testEveryNewTableDeclaresEngineAndCollation(MigrationFile $file): void
    {
        foreach ($file->statements() as $statement) {
            if (preg_match('/^CREATE TABLE/i', $statement) === 1) {
                self::assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci', $statement);
            }
        }
    }

    public function testStatementSplittingKeepsSemicolonsInsideLines(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mig');
        self::assertIsString($path);
        file_put_contents($path, "-- comment; not a statement\nCREATE TABLE a (v VARCHAR(5) DEFAULT ';');\n\nINSERT INTO a VALUES ('x');\n");
        $statements = (new MigrationFile(1, 'x', $path, ''))->statements();
        unlink($path);
        self::assertSame(["CREATE TABLE a (v VARCHAR(5) DEFAULT ';')", "INSERT INTO a VALUES ('x')"], $statements);
    }
}
