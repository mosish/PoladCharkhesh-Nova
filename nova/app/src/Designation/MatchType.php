<?php

declare(strict_types=1);

namespace Nova\Designation;

/**
 * Match types recorded for a requested designation (05 §10). The seventh
 * Owner category, a known spelling, is recorded as its target's type with
 * via = spelling.
 */
enum MatchType: string
{
    case ExactProduct = 'exact_product';
    case ExactVariant = 'exact_variant';
    case UnlistedVariant = 'unlisted_variant';
    case Related = 'related';
    case BaseSeries = 'base_series';
    case None = 'none';
}
