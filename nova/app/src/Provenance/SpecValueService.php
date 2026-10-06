<?php

declare(strict_types=1);

namespace Nova\Provenance;

use DateTimeImmutable;
use DateTimeZone;
use Nova\Audit\AuditLog;
use Nova\Catalogue\CatalogueError;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Security\Actor;
use PDO;

/**
 * Per-value provenance (05 §11). A value belongs to exactly one designation
 * and is never copied to or inherited by another. "Verified" needs a citable
 * source and a checked date; everything else is pending, shown as pending,
 * never guessed. Every change is audited, and a core change resets page
 * verification (E-9).
 */
final readonly class SpecValueService
{
    private const string NUMBER = '/^-?[0-9]+(?:\.[0-9]+)?$/';

    public function __construct(private PDO $pdo, private AuditLog $audit) {}

    /**
     * Creates or replaces the value of `$specKeyId` for one designation.
     * `$expectedVersion` is required when a value already exists.
     */
    public function setValue(Actor $actor, DesignationRef $ref, int $specKeyId, ValueInput $input, ?int $expectedVersion = null): int
    {
        return Transaction::run($this->pdo, function () use ($actor, $ref, $specKeyId, $input, $expectedVersion): int {
            [$productId, $label] = $this->designation($ref);
            $key = Sql::row($this->pdo, 'SELECT k.*, (k.family_id = p.family_id) AS same_family FROM spec_key k JOIN product p ON p.id = ? WHERE k.id = ?', [$productId, $specKeyId])
                ?? throw CatalogueError::notFound('spec key');
            if ((int) $key['same_family'] !== 1) {
                throw CatalogueError::invalid("The spec key \"{$key['code']}\" belongs to another family.");
            }
            $row = $this->normalise($input, (string) $key['value_type'], $key['unit'] === null ? null : (string) $key['unit']);

            $existing = Sql::row($this->pdo, 'SELECT * FROM spec_value WHERE spec_key_id = ? AND ' . ($ref->variantId !== null ? 'variant_id = ?' : 'product_id = ?') . ' FOR UPDATE', [$specKeyId, $ref->variantId ?? $ref->productId]);
            if ($existing === null) {
                Sql::run($this->pdo, 'INSERT INTO spec_value (product_id, variant_id, spec_key_id, value_text, value_num, unit, status, source_id, source_locator, checked_on, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$ref->productId, $ref->variantId, $specKeyId, ...array_values($row), $actor->userId]);
                $id = (int) $this->pdo->lastInsertId();
                $before = null;
            } else {
                $id = (int) $existing['id'];
                if ($expectedVersion === null || (int) $existing['row_version'] !== $expectedVersion) {
                    throw CatalogueError::stale('value');
                }
                Sql::run($this->pdo, 'UPDATE spec_value SET value_text = ?, value_num = ?, unit = ?, status = ?, source_id = ?, source_locator = ?, checked_on = ?, updated_by = ?, row_version = row_version + 1 WHERE id = ?', [...array_values($row), $actor->userId, $id]);
                $before = array_intersect_key($existing, $row);
            }

            // value_num is derived from value_text (and comes back as DECIMAL text), so it is not compared.
            $compare = static fn(array $r): array => array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', array_diff_key($r, ['value_num' => true]));
            $changed = $before === null || $compare($before) !== $compare($row);
            if ($changed && (int) $key['is_core'] === 1) {
                VerificationReset::product($this->pdo, $this->audit, $actor, $productId, "core value {$key['code']} of {$label} changed", $ref->variantId);
            }
            $this->audit->record($actor, $before === null ? 'value.create' : 'value.update', 'spec_value', $id, "{$label} · {$key['code']}", $before, $row);

            return $id;
        });
    }

    public function removeValue(Actor $actor, int $valueId, int $expectedVersion): void
    {
        Transaction::run($this->pdo, function () use ($actor, $valueId, $expectedVersion): void {
            $row = Sql::row($this->pdo, 'SELECT v.*, k.code, k.is_core, COALESCE(v.product_id, pv.product_id) AS owner_product FROM spec_value v JOIN spec_key k ON k.id = v.spec_key_id LEFT JOIN product_variant pv ON pv.id = v.variant_id WHERE v.id = ? FOR UPDATE', [$valueId])
                ?? throw CatalogueError::notFound('value');
            if ((int) $row['row_version'] !== $expectedVersion) {
                throw CatalogueError::stale('value');
            }
            Sql::run($this->pdo, 'DELETE FROM spec_value WHERE id = ?', [$valueId]);
            if ((int) $row['is_core'] === 1) {
                VerificationReset::product($this->pdo, $this->audit, $actor, (int) $row['owner_product'], "core value {$row['code']} removed", $row['variant_id'] === null ? null : (int) $row['variant_id']);
            }
            $this->audit->record($actor, 'value.delete', 'spec_value', $valueId, (string) $row['code'], $row, null);
        });
    }

    /** @return array{int, string} the owning Product id and the designation's label */
    private function designation(DesignationRef $ref): array
    {
        if ($ref->variantId !== null) {
            $row = Sql::row($this->pdo, 'SELECT product_id, designation FROM product_variant WHERE id = ?', [$ref->variantId]) ?? throw CatalogueError::notFound('variant');

            return [(int) $row['product_id'], (string) $row['designation']];
        }
        $row = Sql::row($this->pdo, 'SELECT id, designation FROM product WHERE id = ?', [(int) $ref->productId]) ?? throw CatalogueError::notFound('Product');

        return [(int) $row['id'], (string) $row['designation']];
    }

    /** @return array{value_text: string, value_num: ?string, unit: ?string, status: string, source_id: ?int, source_locator: ?string, checked_on: ?string} */
    private function normalise(ValueInput $input, string $type, ?string $defaultUnit): array
    {
        $text = trim(strtr($input->valueText, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.']));
        if ($text === '' || mb_strlen($text, 'UTF-8') > 255) {
            throw CatalogueError::invalid('A value is required (at most 255 characters).');
        }
        $number = null;
        if ($type === 'number') {
            if (preg_match(self::NUMBER, $text) !== 1) {
                throw CatalogueError::invalid("\"{$text}\" is not a number.");
            }
            $number = $text;
        }
        if (!\in_array($input->status, ['pending', 'verified'], true)) {
            throw CatalogueError::invalid("Unknown status \"{$input->status}\".");
        }
        $checked = null;
        if ($input->checkedOn !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input->checkedOn, new DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d') !== $input->checkedOn) {
                throw CatalogueError::invalid('The checked date must be a date (YYYY-MM-DD).');
            }
            if ($date > new DateTimeImmutable('tomorrow', new DateTimeZone('Asia/Tehran'))) {
                throw CatalogueError::invalid('The checked date cannot be in the future.');
            }
            $checked = $input->checkedOn;
        }
        if ($input->sourceId !== null && Sql::int($this->pdo, 'SELECT COUNT(*) FROM source WHERE id = ?', [$input->sourceId]) === 0) {
            throw CatalogueError::notFound('source');
        }
        if ($input->status === 'verified' && ($input->sourceId === null || $checked === null)) {
            throw CatalogueError::invalid('A verified value needs a cited source and the date it was checked.');
        }

        return [
            'value_text' => $text,
            'value_num' => $number,
            'unit' => $input->unit ?? $defaultUnit,
            'status' => $input->status,
            'source_id' => $input->sourceId,
            'source_locator' => $input->sourceLocator,
            'checked_on' => $checked,
        ];
    }
}
