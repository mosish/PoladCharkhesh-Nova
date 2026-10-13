<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Admin;

use Nova\Admin\AdminApp;
use Nova\Admin\AdminView;
use Nova\Admin\PublishAdmin;
use Nova\Core\Environment;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Publish\AssetBuilder;
use Nova\Publish\BuildConfig;
use Nova\Publish\PublishEngine;
use Nova\Publish\PublishJournal;
use Nova\Publish\SiteBuilder;
use Nova\Publish\SiteData;
use Nova\Publish\SymlinkSwitcher;
use Nova\Security\ArraySessionStore;
use Nova\Security\Totp;
use Nova\Security\UserAdmin;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use Nova\Tests\Integration\Publish\TestClock;
use PHPUnit\Framework\TestCase;

/**
 * The Admin through its front controller, in process (05 §6, §14): the host
 * rule, sign-in, CSRF and origin checks, the role policy, and the
 * Product/variant slice down to a publish and a rollback into a temporary
 * site directory. Test data only; the disposable test database.
 */
final class AdminAppTest extends TestCase
{
    private const string HOST = 'fa.example.test';
    private const string PASSWORD = 'a long test passphrase';

    private static string $assets;
    private string $tmp;
    private CatalogueFixture $f;
    private ArraySessionStore $session;
    private TestClock $clock;
    private AdminApp $app;
    private int $product;

    public static function setUpBeforeClass(): void
    {
        self::$assets = sys_get_temp_dir() . '/nova-admin-assets-' . bin2hex(random_bytes(4));
        $manifest = AssetBuilder::build(Paths::fromHere()->root . '/assets-src', self::$assets . '/assets');
        AssetBuilder::buildAdmin(Paths::fromHere()->root . '/assets-src', $manifest, self::$assets . '/admin');
    }

    public static function tearDownAfterClass(): void
    {
        Files::removeTree(self::$assets);
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-admin-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/site', 0775, true);
        $this->f = new CatalogueFixture();
        $users = new UserAdmin($this->f->pdo, ['origin' => 'cli']);
        $users->resetPassword('owner@test.invalid', self::PASSWORD);
        $users->resetPassword('editor@test.invalid', self::PASSWORD);
        $this->product = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        Sql::run($this->f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$this->product]);
        $this->clock = new TestClock(1_800_000_000);
        $this->session = new ArraySessionStore();
        $this->app = $this->app();
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->tmp);
    }

    private function app(): AdminApp
    {
        $root = Paths::fromHere()->root;
        $config = new BuildConfig(['fa' => 'https://fa.example.test', 'en' => 'https://en.example.test'], false, true);
        $contact = $root . '/app/i18n/contact.php';
        $builder = new SiteBuilder($root . '/app', self::$assets . '/assets', $config);
        $pdo = $this->f->pdo;
        $tmp = $this->tmp;

        return new AdminApp(
            $pdo,
            $this->session,
            new AdminView($root . '/app', AdminView::manifest(self::$assets . '/admin')),
            self::HOST,
            static fn(): PublishEngine => new PublishEngine($pdo, new SymlinkSwitcher($tmp . '/site'), new PublishJournal($tmp . '/journal.json'), $builder, $config, Environment::Production, $contact),
            new PublishAdmin($pdo, $config, Environment::Production, $contact),
            static function (int $id, string $lang) use ($pdo, $builder, $contact): ?string {
                $data = SiteData::preview($pdo, $id, false, $contact);

                return $data === null ? null : $builder->previewPage($data, $lang);
            },
            'https://fa.example.test/',
            $this->clock,
        );
    }

    /** @param array<string, mixed> $post */
    private function req(string $method, string $path, array $post = [], string $host = self::HOST, ?string $fetchSite = 'same-origin', bool $token = true): Response
    {
        if ($method === 'POST' && $token && !isset($post['_csrf'])) {
            $post['_csrf'] = $this->sessionString('csrf');
        }

        return $this->app->handle(new Request($method, $path, $host, [], $post, '192.0.2.10', null, $method === 'POST' ? $fetchSite : null));
    }

    private function signInEditor(): void
    {
        $this->req('GET', '/admin/login');
        $r = $this->req('POST', '/admin/login', ['email' => 'editor@test.invalid', 'password' => self::PASSWORD]);
        self::assertSame('/admin/', $r->headers['Location'] ?? null);
    }

    private function signInOwner(): void
    {
        $this->req('GET', '/admin/login');
        $r = $this->req('POST', '/admin/login', ['email' => 'owner@test.invalid', 'password' => self::PASSWORD]);
        self::assertSame('/admin/login/enroll', $r->headers['Location'] ?? null);
        $page = $this->req('GET', '/admin/login/enroll');
        self::assertSame(200, $page->status);
        $secret = $this->sessionString('enroll_secret');
        self::assertStringContainsString(Totp::grouped($secret), $page->body);
        $r = $this->req('POST', '/admin/login/enroll', ['code' => Totp::code($secret, Totp::step($this->clock->now()))]);
        self::assertSame('/admin/', $r->headers['Location'] ?? null);
        self::assertSame('ok', $this->flash()[0]['type'] ?? null, 'enrolment is confirmed');
        $this->session->remove('flash');
    }

    private function sessionString(string $key): string
    {
        $value = $this->session->get($key);

        return \is_string($value) ? $value : '';
    }

    private function version(): int
    {
        return $this->f->version('product', $this->product);
    }

    /** @return list<array{type: string, text: string, detail: ?string}> */
    private function flash(): array
    {
        $flash = $this->session->get('flash');

        /** @var list<array{type: string, text: string, detail: ?string}> */
        return \is_array($flash) ? $flash : [];
    }

    // ---- access control

    public function testTheAdminAnswersOnlyOnItsHost(): void
    {
        foreach (['en.example.test', 'evil.test', 'fa.example.test.evil.test', ''] as $host) {
            self::assertSame(404, $this->req('GET', '/admin/login', [], $host)->status, $host);
        }
        self::assertSame(200, $this->req('GET', '/admin/login', [], 'fa.example.test:8443')->status, 'a port does not matter');
    }

    public function testEverythingButSignInNeedsASession(): void
    {
        foreach (['/admin/', '/admin/products', '/admin/products/' . $this->product, '/admin/publish', '/admin/products/' . $this->product . '/preview/fa'] as $path) {
            $r = $this->req('GET', $path);
            self::assertSame(303, $r->status, $path);
            self::assertSame('/admin/login', $r->headers['Location'] ?? null);
        }
        $this->req('GET', '/admin/login');
        $r = $this->req('POST', '/admin/products', ['designation' => 'QZ 9', 'family_id' => (string) $this->f->family]);
        self::assertSame('/admin/login', $r->headers['Location'] ?? null, 'a signed-out POST changes nothing');
        self::assertSame(0, Sql::int($this->f->pdo, "SELECT COUNT(*) FROM product WHERE designation = 'QZ 9'"));
    }

    public function testEveryPostNeedsTheTokenAndASameOriginRequest(): void
    {
        $this->signInEditor();
        $post = ['designation' => 'QZ 9', 'family_id' => (string) $this->f->family];
        self::assertSame(403, $this->req('POST', '/admin/products', $post, token: false)->status, 'no token');
        self::assertSame(403, $this->req('POST', '/admin/products', $post + ['_csrf' => str_repeat('0', 64)])->status, 'wrong token');
        self::assertSame(403, $this->req('POST', '/admin/products', $post, fetchSite: 'cross-site')->status, 'cross-site, even with the token');
        self::assertSame(403, $this->app->handle(new Request('POST', '/admin/products', self::HOST, [], $post + ['_csrf' => $this->sessionString('csrf')], '192.0.2.10', 'https://evil.test', null))->status, 'foreign Origin');
        self::assertSame(0, Sql::int($this->f->pdo, "SELECT COUNT(*) FROM product WHERE designation = 'QZ 9'"));
        self::assertSame(303, $this->req('POST', '/admin/products', $post)->status);
        self::assertSame(1, Sql::int($this->f->pdo, "SELECT COUNT(*) FROM product WHERE designation = 'QZ 9' AND status = 'draft'"));
    }

    public function testTheLoginFormFailsGenericallyAndTheTokenGuardsItToo(): void
    {
        $this->req('GET', '/admin/login');
        $r = $this->req('POST', '/admin/login', ['email' => 'editor@test.invalid', 'password' => 'wrong password!!']);
        self::assertSame(401, $r->status);
        self::assertStringContainsString('ورود ناموفق بود', $r->body);
        self::assertSame(403, $this->req('POST', '/admin/login', ['email' => 'editor@test.invalid', 'password' => self::PASSWORD], token: false)->status);
    }

    public function testAnEditorIsRefusedOwnerOnlyActions(): void
    {
        $this->signInEditor();
        $source = (string) $this->f->source;
        $refused = [
            "/admin/products/{$this->product}/verify" => ['version' => (string) $this->version()],
            "/admin/products/{$this->product}/variants" => ['designation' => 'QZ 1 X', 'source' => $source, 'listed' => '1'],
            "/admin/products/{$this->product}/spellings" => ['spelling' => 'QZ-1', 'target' => 'base'],
            "/admin/products/{$this->product}/standalone" => ['note' => 'x'],
        ];
        foreach ($refused as $path => $post) {
            self::assertSame(403, $this->req('POST', $path, $post)->status, $path);
        }
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM product_variant'));
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM spelling'));
        $editor = $this->req('GET', "/admin/products/{$this->product}");
        self::assertStringNotContainsString("action=\"/admin/products/{$this->product}/variants\"", $editor->body, 'no owner-only controls are offered');
        self::assertStringNotContainsString("action=\"/admin/products/{$this->product}/verify\"", $editor->body);
        self::assertStringContainsString('فقط توسط مالک', $editor->body);
    }

    public function testSignOutEndsTheSession(): void
    {
        $this->signInEditor();
        self::assertSame('/admin/login', $this->req('POST', '/admin/logout')->headers['Location'] ?? null);
        self::assertSame('/admin/login', $this->req('GET', '/admin/')->headers['Location'] ?? null);
    }

    // ---- the vertical slice

    public function testDashboardListAndEditorRender(): void
    {
        $this->signInEditor();
        $dash = $this->req('GET', '/admin/');
        self::assertSame(200, $dash->status);
        self::assertStringContainsString('lang="fa" dir="rtl"', $dash->body);
        self::assertStringContainsString('پیشخوان', $dash->body);
        self::assertStringContainsString('noindex', $dash->body);
        self::assertStringNotContainsString('style="', $dash->body, 'no inline styles (CSP)');
        self::assertStringNotContainsString('<script>', $dash->body, 'no inline scripts (CSP)');

        $list = $this->req('GET', '/admin/products');
        self::assertStringContainsString('QZ 1', $list->body);
        // The same normaliser as public search: Persian digits and spacing.
        $found = $this->app->handle(new Request('GET', '/admin/products', self::HOST, ['q' => 'qz۱']));
        self::assertStringContainsString("/admin/products/{$this->product}", $found->body);
        $none = $this->app->handle(new Request('GET', '/admin/products', self::HOST, ['q' => 'ZZZ']));
        self::assertStringContainsString('محصولی با این جستجو پیدا نشد', $none->body);

        $editor = $this->req('GET', "/admin/products/{$this->product}");
        self::assertSame(200, $editor->status);
        self::assertStringContainsString('ابعاد و داده‌ها', $editor->body);
        self::assertSame(404, $this->req('GET', '/admin/products/999999')->status);
        self::assertSame(404, $this->req('GET', '/admin/nothing-here')->status);
    }

    public function testValuesCarryTheirOwnProvenance(): void
    {
        $this->signInEditor();
        $path = "/admin/products/{$this->product}/values";
        $this->req('POST', $path, ['item' => 'base', 'key' => (string) $this->f->keyD, 'value' => '25', 'status' => 'verified', 'source' => '', 'checked_on' => '']);
        self::assertSame('error', $this->flash()[0]['type'] ?? null, 'verified without a source is refused');
        self::assertStringContainsString('cited source', (string) ($this->flash()[0]['detail'] ?? ''));
        $this->session->remove('flash');

        $this->req('POST', $path, ['item' => 'base', 'key' => (string) $this->f->keyD, 'value' => '۲۵', 'status' => 'verified', 'source' => (string) $this->f->source, 'checked_on' => '2026-10-01', 'locator' => 'p. 12']);
        self::assertSame('ok', $this->flash()[0]['type'] ?? null);
        $row = Sql::row($this->f->pdo, 'SELECT value_text, status, source_id, source_locator, checked_on, row_version FROM spec_value WHERE product_id = ? AND spec_key_id = ?', [$this->product, $this->f->keyD]);
        self::assertSame(['25', 'verified', (string) $this->f->source, 'p. 12', '2026-10-01'], [$row['value_text'] ?? null, $row['status'] ?? null, (string) ($row['source_id'] ?? ''), $row['source_locator'] ?? null, $row['checked_on'] ?? null]);

        // A stale edit (someone else saved in between) is refused, not merged.
        $this->session->remove('flash');
        $this->req('POST', $path, ['item' => 'base', 'key' => (string) $this->f->keyD, 'value' => '26', 'status' => 'pending', 'source' => '', 'version' => '999']);
        self::assertSame('error', $this->flash()[0]['type'] ?? null);
        self::assertStringContainsString('کس دیگری', $this->flash()[0]['text']);
        self::assertSame('25', Sql::scalar($this->f->pdo, 'SELECT value_text FROM spec_value WHERE product_id = ? AND spec_key_id = ?', [$this->product, $this->f->keyD]));
        self::assertContains('value.create', $this->f->auditActions());
    }

    public function testOwnerRecordsAVariantWithASourceAndItsValuesStaySeparate(): void
    {
        $this->signInOwner();
        $this->req('POST', "/admin/products/{$this->product}/variants", ['designation' => 'QZ 1 X', 'source' => '', 'listed' => '1']);
        self::assertSame('error', $this->flash()[0]['type'] ?? null, 'a relationship needs a source');
        $this->session->remove('flash');

        $this->req('POST', "/admin/products/{$this->product}/variants", ['designation' => 'QZ 1 X', 'source' => (string) $this->f->source, 'listed' => '1']);
        $variant = (int) Sql::scalar($this->f->pdo, "SELECT id FROM product_variant WHERE designation = 'QZ 1 X'");
        self::assertGreaterThan(0, $variant);
        $this->req('POST', "/admin/products/{$this->product}/values", ['item' => "v{$variant}", 'key' => (string) $this->f->keyD, 'value' => '30', 'status' => 'pending', 'source' => '']);
        self::assertSame(1, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM spec_value WHERE variant_id = ?', [$variant]));
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM spec_value WHERE product_id = ?', [$this->product]), 'nothing was copied to the base designation');

        // Another Product's variant cannot be reached through this Product's URL.
        $other = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 2');
        $this->session->remove('flash');
        $this->req('POST', "/admin/products/{$other}/values", ['item' => "v{$variant}", 'key' => (string) $this->f->keyD, 'value' => '31', 'status' => 'pending', 'source' => '']);
        self::assertSame('error', $this->flash()[0]['type'] ?? null);
        self::assertSame('30', Sql::scalar($this->f->pdo, 'SELECT value_text FROM spec_value WHERE variant_id = ?', [$variant]));
    }

    public function testSaveIdentityPublicationAndDescription(): void
    {
        $this->signInEditor();
        $this->req('POST', "/admin/products/{$this->product}", ['version' => (string) $this->version(), 'designation' => 'QZ 1', 'base_listed' => '1', 'show_in_explorer' => '1', 'status' => 'draft']);
        self::assertSame(['draft', '1'], [Sql::scalar($this->f->pdo, 'SELECT status FROM product WHERE id = ?', [$this->product]), (string) Sql::scalar($this->f->pdo, 'SELECT show_in_explorer FROM product WHERE id = ?', [$this->product])]);

        $this->req('POST', "/admin/products/{$this->product}/description", ['version' => (string) $this->version(), 'lang' => 'en', 'text' => "Deep groove **ball** bearing.\n\n- sealed\n- `QZ 1`"]);
        $doc = json_decode((string) Sql::scalar($this->f->pdo, 'SELECT description_en FROM product WHERE id = ?', [$this->product]), true);
        self::assertIsArray($doc);
        self::assertIsArray($doc['blocks']);
        self::assertSame(['paragraph', 'list'], array_column($doc['blocks'], 'type'));
        $editor = $this->req('GET', "/admin/products/{$this->product}");
        self::assertStringContainsString('Deep groove **ball** bearing.', $editor->body, 'the stored document reads back in the editor syntax');

        $this->session->remove('flash');
        $this->req('POST', "/admin/products/{$this->product}/description", ['version' => '1', 'lang' => 'fa', 'text' => 'متن']);
        self::assertSame('error', $this->flash()[0]['type'] ?? null, 'stale version');
    }

    public function testVerificationIsOwnerOnlyAndGated(): void
    {
        $this->signInOwner();
        $this->req('POST', "/admin/products/{$this->product}/verify", ['version' => (string) $this->version()]);
        self::assertSame('error', $this->flash()[0]['type'] ?? null, 'core values are missing');
        self::assertSame('pending', Sql::scalar($this->f->pdo, 'SELECT verification FROM product WHERE id = ?', [$this->product]));
        foreach ([$this->f->keyD, $this->f->keyBigD] as $key) {
            $this->req('POST', "/admin/products/{$this->product}/values", ['item' => 'base', 'key' => (string) $key, 'value' => '10', 'status' => 'verified', 'source' => (string) $this->f->source, 'checked_on' => '2026-10-01']);
        }
        $this->session->remove('flash');
        $this->req('POST', "/admin/products/{$this->product}/verify", ['version' => (string) $this->version()]);
        self::assertSame('ok', $this->flash()[0]['type'] ?? null);
        self::assertSame('verified', Sql::scalar($this->f->pdo, 'SELECT verification FROM product WHERE id = ?', [$this->product]));
        self::assertStringContainsString('index, follow', $this->req('GET', "/admin/products/{$this->product}")->body);
    }

    public function testDraftPreviewUsesThePublishTemplatesAndIsNoindex(): void
    {
        $this->signInEditor();
        Sql::run($this->f->pdo, "UPDATE product SET status = 'draft' WHERE id = ?", [$this->product]);
        foreach (['fa', 'en'] as $lang) {
            $r = $this->req('GET', "/admin/products/{$this->product}/preview/{$lang}");
            self::assertSame(200, $r->status);
            self::assertStringContainsString('QZ 1', $r->body);
            self::assertStringContainsString('<meta name="robots" content="noindex">', $r->body);
            self::assertStringContainsString($lang === 'fa' ? 'پیش‌نمایش پیش‌نویس' : 'Admin draft preview', $r->body);
            self::assertSame('no-store', $r->headers['Cache-Control']);
            self::assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        }
        self::assertSame([], glob($this->tmp . '/site/*') ?: [], 'a preview writes nothing');
        self::assertSame(404, $this->req('GET', '/admin/products/999999/preview/fa')->status);
    }

    public function testPublishAndRollbackFromTheAdmin(): void
    {
        $this->signInEditor();
        $page = $this->req('GET', '/admin/publish');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('هنوز نسخه‌ای منتشر نشده است', $page->body);

        $this->req('POST', '/admin/publish');
        self::assertSame('ok', $this->flash()[0]['type'] ?? null, (string) ($this->flash()[0]['detail'] ?? ''));
        $first = (string) ($this->flash()[0]['detail'] ?? '');
        self::assertSame($first, (new SymlinkSwitcher($this->tmp . '/site'))->current());
        $after = json_decode((string) Sql::scalar($this->f->pdo, "SELECT after_text FROM audit_event WHERE action = 'publish' ORDER BY id DESC LIMIT 1"), true);
        self::assertIsArray($after);
        self::assertSame(['origin' => 'admin', 'user_id' => $this->f->editor->userId], $after['by']);
        self::assertSame($this->f->editor->userId, (int) Sql::scalar($this->f->pdo, 'SELECT published_by FROM publish_record WHERE release_id = ?', [$first]));

        Sql::run($this->f->pdo, "UPDATE product SET designation = 'QZ 1 B' WHERE id = ?", [$this->product]);
        $this->session->remove('flash');
        $this->req('POST', '/admin/publish');
        $second = (string) ($this->flash()[0]['detail'] ?? '');
        self::assertNotSame($first, $second);
        self::assertStringContainsString('بازگشت…', $this->req('GET', '/admin/publish')->body);

        $this->session->remove('flash');
        $this->req('POST', '/admin/publish/rollback');
        self::assertSame('ok', $this->flash()[0]['type'] ?? null);
        self::assertSame($first, (new SymlinkSwitcher($this->tmp . '/site'))->current());
        self::assertSame(['live', 'rolled_back'], [Sql::scalar($this->f->pdo, 'SELECT status FROM publish_record WHERE release_id = ?', [$first]), Sql::scalar($this->f->pdo, 'SELECT status FROM publish_record WHERE release_id = ?', [$second])]);
    }

    public function testAPublishBlockedByValidationLeavesTheSiteAlone(): void
    {
        $this->signInEditor();
        Sql::run($this->f->pdo, "INSERT INTO product_variant (product_id, designation, slug, listed, status) VALUES (?, 'QZ 1 Y', 'qz-1-y', 1, 'published')", [$this->product]);
        $page = $this->req('GET', '/admin/publish');
        self::assertStringContainsString('QZ 1 Y', $page->body, 'the dry run shows the blocker');
        self::assertMatchesRegularExpression('/<button class="a-btn a-btn--lg a-btn--pri" type="submit" disabled>/', $page->body);
        $this->req('POST', '/admin/publish');
        self::assertSame('error', $this->flash()[0]['type'] ?? null);
        self::assertNull((new SymlinkSwitcher($this->tmp . '/site'))->current());
    }
}
