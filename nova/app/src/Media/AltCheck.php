<?php

declare(strict_types=1);

namespace Nova\Media;

use Nova\Core\Sql;
use PDO;

/**
 * ALT-INF (frozen G2-15): an informative image without alt text in both
 * languages blocks publishing while a published page uses it. A decorative
 * image needs none; a missing or trashed item is a broken reference, which
 * the render check reports.
 */
final class AltCheck
{
    /** @return list<string> */
    public static function problems(PDO $pdo): array
    {
        $problems = [];
        foreach ((new MediaReferences($pdo))->usedByPublished() as $id => $products) {
            $row = Sql::row($pdo, 'SELECT original_name, role, alt_fa, alt_en, trashed_at FROM media WHERE id = ?', [$id]);
            if ($row === null || $row['trashed_at'] !== null) {
                continue;
            }
            if ($row['role'] === 'informative' && (trim((string) $row['alt_fa']) === '' || trim((string) $row['alt_en']) === '')) {
                $problems[] = "media {$row['original_name']} (used by " . implode(', ', $products) . '): informative image without alt text in both languages (ALT-INF)';
            }
        }

        return $problems;
    }
}
