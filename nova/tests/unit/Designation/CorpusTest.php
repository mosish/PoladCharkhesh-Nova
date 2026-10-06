<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Designation;

use Nova\Designation\DesignationIndex;
use Nova\Designation\InvalidDesignationIndex;
use Nova\Designation\Matcher;
use Nova\Designation\Normaliser;
use Nova\Designation\RequestedDesignation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared behavioural corpus (tests/fixtures/designation/corpus.json),
 * the same vectors tests/js/designation.test.js runs against the JavaScript copy.
 * Fixtures only: nothing in the corpus is production catalogue data.
 */
final class CorpusTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $corpus = null;

    /** @return array<string, mixed> */
    private static function corpus(): array
    {
        if (self::$corpus === null) {
            $json = file_get_contents(\dirname(__DIR__, 2) . '/fixtures/designation/corpus.json');
            self::assertIsString($json);
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            self::$corpus = $decoded;
        }

        return self::$corpus;
    }

    /** @return list<array<string, mixed>> */
    private static function section(string $name): array
    {
        $section = self::corpus()[$name];
        self::assertIsArray($section);
        /** @var list<array<string, mixed>> $section */
        return $section;
    }

    /**
     * @param list<array<string, mixed>> $cases
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    private static function named(array $cases): iterable
    {
        foreach ($cases as $i => $case) {
            $label = \is_string($case['input'] ?? null) ? $case['input'] : (\is_string($case['note'] ?? null) ? $case['note'] : '');
            yield \sprintf('#%d %s', $i, json_encode($label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function normaliseCases(): iterable
    {
        return self::named(self::section('normalise'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function requestedCases(): iterable
    {
        return self::named(self::section('requested'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function matchCases(): iterable
    {
        return self::named(self::section('match'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidIndexCases(): iterable
    {
        return self::named(self::section('invalid_index'));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('normaliseCases')]
    public function testNormalise(array $case): void
    {
        self::assertIsString($case['input']);
        self::assertSame($case['key'], Normaliser::key($case['input']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('requestedCases')]
    public function testRequested(array $case): void
    {
        self::assertIsString($case['input']);
        $requested = RequestedDesignation::from($case['input']);
        self::assertSame($case['requested'], $requested->text);
        self::assertSame($case['too_long'], $requested->tooLong);
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('matchCases')]
    public function testMatch(array $case): void
    {
        self::assertIsString($case['catalogue']);
        self::assertIsString($case['input']);
        $catalogues = self::corpus()['catalogues'];
        self::assertIsArray($catalogues);
        $catalogue = $catalogues[$case['catalogue']];
        self::assertIsArray($catalogue);

        $matcher = new Matcher(DesignationIndex::fromArray($catalogue['index']));
        self::assertSame($case['expect'], $matcher->match($case['input'])->toArray());
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('invalidIndexCases')]
    public function testInvalidIndexIsRejected(array $case): void
    {
        try {
            DesignationIndex::fromArray($case['index']);
            self::fail('Index was accepted');
        } catch (InvalidDesignationIndex $e) {
            self::assertSame($case['error'], $e->error, $e->getMessage());
        }
    }

    public function testEveryCatalogueInTheCorpusIsValid(): void
    {
        $catalogues = self::corpus()['catalogues'];
        self::assertIsArray($catalogues);
        foreach ($catalogues as $catalogue) {
            self::assertIsArray($catalogue);
            DesignationIndex::fromArray($catalogue['index']);
        }
        self::assertSame(RequestedDesignation::MAX_LENGTH, self::corpus()['requested_max_length']);
    }
}
