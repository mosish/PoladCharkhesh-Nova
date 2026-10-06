<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Migration;

use Nova\Migration\BackupGuard;
use PDO;

/** Test double: records each backup request and returns a fixed reference. */
final class RecordingBackupGuard implements BackupGuard
{
    /** @var list<string> */
    public array $calls = [];

    public function ensureBackup(PDO $pdo, string $reason): string
    {
        $this->calls[] = $reason;

        return 'backup-test-1';
    }
}
