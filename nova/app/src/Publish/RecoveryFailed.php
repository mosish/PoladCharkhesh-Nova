<?php

declare(strict_types=1);

namespace Nova\Publish;

use RuntimeException;
use Throwable;

/**
 * A publish or rollback failed and the recovery that should have finished or
 * undone it failed as well: the live state is not known. The journal is kept,
 * so `publish:status` and `publish:recover` (or the next Admin request) can
 * try again.
 */
final class RecoveryFailed extends RuntimeException
{
    public function __construct(public readonly Throwable $failure, public readonly Throwable $recovery)
    {
        parent::__construct('The operation failed (' . $failure->getMessage() . ') and recovering from it failed too (' . $recovery->getMessage() . '); the live release could not be verified. Run publish:status and publish:recover.', 0, $recovery);
    }
}
