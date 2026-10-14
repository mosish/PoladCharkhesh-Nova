<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Designation\DesignationIndex;
use Nova\Designation\IndexedProduct;
use Nova\Designation\Matcher;
use Nova\Designation\MatchType;
use Nova\Designation\Normaliser;

/**
 * One requested designation and the match the server computed for it
 * against the live release (05 §10, §13; E-21).
 *
 * - `requested` is the visitor's text, trimmed and never rewritten: a
 *   suffix such as -2RS or /C3 is kept exactly as typed.
 * - The match comes from `Matcher` alone. The page's claims (product, item,
 *   match type) are never the match; they only say which listed item the
 *   visitor chose, and that choice is kept only when it is one of the items
 *   the server's own match offers. `contextDiffers` records that the page
 *   claimed something else.
 */
final readonly class InquiryItem
{
    /** @param list<string> $listed the listed items named to the visitor (related, base series) */
    public function __construct(
        public string $requested,
        public string $key,
        public ?MatchType $type = null,
        public ?string $spelling = null,
        public ?string $reason = null,
        public ?string $productRef = null,
        public ?string $productDesignation = null,
        public ?string $variantRef = null,
        public ?string $variantDesignation = null,
        public ?string $chosenRef = null,
        public ?string $chosenDesignation = null,
        public array $listed = [],
        public bool $contextDiffers = false,
    ) {}

    /** No release could be read: the request is kept, the match stays open. */
    public static function unmatched(string $requested): self
    {
        return new self($requested, Normaliser::key($requested));
    }

    /** @param array{product: string, item: string, match: string} $claimed */
    public static function match(DesignationIndex $index, string $requested, array $claimed): self
    {
        $m = (new Matcher($index))->match($requested);
        $product = null;
        foreach ($index->products() as $p) {
            if ($p->id === $m->product) {
                $product = $p;
                break;
            }
        }
        $isVariant = $m->variant !== null;
        [$chosenRef, $chosenDesignation] = self::chosen($m->type, $product, $m->variant, $m->designation, $claimed['item']);

        $differs = ($claimed['match'] !== '' && $claimed['match'] !== 'browse' && $claimed['match'] !== $m->type->value)
            || ($claimed['product'] !== '' && 'p:' . $claimed['product'] !== $m->product)
            || ($claimed['item'] !== '' && self::claimRef($claimed['item'], $product) !== $chosenRef);

        return new self(
            $m->requested,
            $m->key,
            $m->type,
            $m->spelling,
            $m->reason?->value,
            $m->product,
            $product?->designation,
            $m->variant,
            $isVariant ? $m->designation : null,
            $chosenRef,
            $chosenDesignation,
            \in_array($m->type, [MatchType::Related, MatchType::BaseSeries], true) ? $m->listed : [],
            $differs,
        );
    }

    /**
     * The listed item the inquiry is about. Exact matches name themselves;
     * for a related or base-series match the visitor may have picked one of
     * the listed items shown, and only those count.
     *
     * @return array{?string, ?string}
     */
    private static function chosen(MatchType $type, ?IndexedProduct $product, ?string $variant, ?string $designation, string $claimedItem): array
    {
        if ($product === null) {
            return [null, null];
        }

        return match ($type) {
            MatchType::ExactVariant => [$variant, $designation],
            MatchType::ExactProduct => [$product->id, $product->designation],
            MatchType::Related, MatchType::BaseSeries => self::listedItem($product, self::claimRef($claimedItem, $product)),
            default => [null, null],
        };
    }

    /** @return array{?string, ?string} */
    private static function listedItem(IndexedProduct $product, ?string $ref): array
    {
        if ($ref === null) {
            return [null, null];
        }
        if ($ref === $product->id) {
            return $product->baseListed ? [$product->id, $product->designation] : [null, null];
        }
        foreach ($product->variants as $v) {
            if ($v->id === $ref && $v->listed) {
                return [$v->id, $v->designation];
            }
        }

        return [null, null];
    }

    /** The page's item value ('base' or a variant slug) as a release id. */
    private static function claimRef(string $item, ?IndexedProduct $product): ?string
    {
        if ($item === '') {
            return null;
        }

        return $item === 'base' ? $product?->id : 'v:' . $item;
    }
}
