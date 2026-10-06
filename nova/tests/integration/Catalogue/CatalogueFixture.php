<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Catalogue;

use Nova\Audit\AuditLog;
use Nova\Catalogue\ProductService;
use Nova\Core\Sql;
use Nova\Provenance\PageVerification;
use Nova\Provenance\SpecValueService;
use Nova\Security\Actor;
use Nova\Security\Role;
use Nova\Tests\Integration\TestDatabase;
use PDO;

/** A migrated nova_test with test users, a family with spec keys and a source. Test data only. */
final class CatalogueFixture
{
    public readonly PDO $pdo;
    public readonly Actor $owner;
    public readonly Actor $editor;
    public readonly int $family;
    public readonly int $otherFamily;
    public readonly int $keyD;
    public readonly int $keyBigD;
    public readonly int $keyNote;
    public readonly int $source;
    public readonly ProductService $products;
    public readonly SpecValueService $values;
    public readonly PageVerification $verification;
    public readonly AuditLog $audit;

    public function __construct()
    {
        $this->pdo = TestDatabase::migrated();
        $user = fn(string $email, string $role): int => TestDatabase::insert($this->pdo, 'app_user', ['email' => $email, 'display_name' => $email, 'role' => $role, 'password_hash' => 'x']);
        $this->owner = new Actor($user('owner@test.invalid', 'owner'), Role::Owner);
        $this->editor = new Actor($user('editor@test.invalid', 'editor'), Role::Editor);
        $family = fn(string $slug): int => TestDatabase::insert($this->pdo, 'family', ['slug' => $slug, 'name_fa' => $slug, 'name_en' => $slug, 'short_fa' => $slug, 'short_en' => $slug, 'definition_fa' => '-', 'definition_en' => '-']);
        $this->family = $family('test-family');
        $this->otherFamily = $family('other-family');
        $key = fn(string $code, int $core, string $type = 'number'): int => TestDatabase::insert($this->pdo, 'spec_key', ['family_id' => $this->family, 'code' => $code, 'label_fa' => $code, 'label_en' => $code, 'unit' => $type === 'number' ? 'mm' : null, 'value_type' => $type, 'is_core' => $core]);
        $this->keyD = $key('d', 1);
        $this->keyBigD = $key('D', 1);
        $this->keyNote = $key('note', 0, 'text');
        $this->source = TestDatabase::insert($this->pdo, 'source', ['title' => 'Test source', 'url' => 'https://example.test/source']);
        $this->audit = new AuditLog($this->pdo);
        $this->products = new ProductService($this->pdo, $this->audit);
        $this->values = new SpecValueService($this->pdo, $this->audit);
        $this->verification = new PageVerification($this->pdo, $this->audit);
    }

    public function version(string $table, int $id): int
    {
        return Sql::int($this->pdo, "SELECT row_version FROM {$table} WHERE id = ?", [$id]);
    }

    public function markPublishedOnce(int $productId): void
    {
        Sql::run($this->pdo, "UPDATE product SET status = 'published', first_published_at = UTC_TIMESTAMP() WHERE id = ?", [$productId]);
    }

    public function key(string $kind, int $id): ?string
    {
        $value = Sql::scalar($this->pdo, "SELECT norm_key FROM designation_key WHERE {$kind}_id = ?", [$id]);

        return $value === null ? null : (string) $value;
    }

    /** @return list<string> */
    public function auditActions(): array
    {
        return array_map(strval(...), Sql::column($this->pdo, 'SELECT action FROM audit_event ORDER BY id'));
    }
}
