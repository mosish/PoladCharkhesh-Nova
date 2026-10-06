<?php

declare(strict_types=1);

namespace Nova\Designation;

/**
 * Classifies a requested designation against the published catalogue
 * (docs/designation-matching.md §4). Authoritative: the inquiry endpoint
 * recomputes every match with this class (E-21).
 *
 * Precedence: exact (directly or through a spelling) › unlisted variant ›
 * related › base series › none. The first four are lookups in one key
 * namespace, so at most one applies; base series is tried only when the
 * lookup misses, and never guesses between candidates.
 */
final readonly class Matcher
{
    public function __construct(private DesignationIndex $index) {}

    public function match(string $raw): MatchResult
    {
        $requested = RequestedDesignation::from($raw);
        $key = Normaliser::key($requested->text);

        if ($requested->tooLong) {
            return new MatchResult($requested->text, $key, MatchType::None, reason: NoMatchReason::TooLong);
        }
        if ($key === '') {
            return new MatchResult($requested->text, $key, MatchType::None, reason: NoMatchReason::Empty);
        }

        $record = $this->index->find($key);
        if ($record instanceof IndexedSpelling) {
            return $this->resolve($requested->text, $key, $record->target, $record->spelling);
        }
        if ($record !== null) {
            return $this->resolve($requested->text, $key, $record, null);
        }

        return $this->baseSeries($requested->text, $key);
    }

    private function resolve(string $requested, string $key, IndexedProduct|IndexedVariant $target, ?string $spelling): MatchResult
    {
        $via = $spelling === null ? null : 'spelling';

        if ($target instanceof IndexedVariant) {
            return new MatchResult(
                $requested,
                $key,
                $target->listed ? MatchType::ExactVariant : MatchType::UnlistedVariant,
                $via,
                $spelling,
                $target->productId,
                $target->id,
                $target->designation,
            );
        }

        // base_listed alone says whether the plain Product designation is listed.
        if ($target->baseListed) {
            return new MatchResult($requested, $key, MatchType::ExactProduct, $via, $spelling, $target->id, null, $target->designation);
        }

        return new MatchResult($requested, $key, MatchType::Related, $via, $spelling, $target->id, null, $target->designation, $target->listedDesignations());
    }

    private function baseSeries(string $requested, string $key): MatchResult
    {
        $candidates = [];
        foreach ($this->index->products() as $product) {
            if (\strlen($key) <= \strlen($product->key) || !str_starts_with($key, $product->key)) {
                continue;
            }
            $tokens = $this->index->suffixTokens($product->family);
            if ($tokens === []) {
                continue;
            }
            $splits = self::splits(substr($key, \strlen($product->key)), $tokens);
            if ($splits !== []) {
                $candidates[] = [$product, $splits];
            }
        }

        if ($candidates === []) {
            return new MatchResult($requested, $key, MatchType::None, reason: NoMatchReason::Unknown);
        }
        [$product, $splits] = $candidates[0];
        if (\count($candidates) > 1 || \count($splits) > 1) {
            return new MatchResult($requested, $key, MatchType::None, reason: NoMatchReason::Ambiguous);
        }

        return new MatchResult($requested, $key, MatchType::BaseSeries, product: $product->id, designation: $product->designation, listed: $product->listedDesignations(), suffix: $splits[0]);
    }

    /**
     * Ways to write `$tail` as a sequence of approved tokens, stopping at two
     * (two already means ambiguous). Counted with dynamic programming so a
     * long tail cannot blow up.
     *
     * @param list<string> $tokens
     *
     * @return list<list<string>> zero, one or two splits
     */
    private static function splits(string $tail, array $tokens): array
    {
        $length = \strlen($tail);
        /** @var array<int, list<list<string>>> $ways ways[i] = splits of substr($tail, i), at most two */
        $ways = [$length => [[]]];
        for ($i = $length - 1; $i >= 0; --$i) {
            $found = [];
            foreach ($tokens as $token) {
                $next = $i + \strlen($token);
                if ($next > $length || substr_compare($tail, $token, $i, \strlen($token)) !== 0) {
                    continue;
                }
                foreach ($ways[$next] as $rest) {
                    $found[] = [$token, ...$rest];
                    if (\count($found) === 2) {
                        break 2;
                    }
                }
            }
            $ways[$i] = $found;
        }

        return $ways[0];
    }
}
