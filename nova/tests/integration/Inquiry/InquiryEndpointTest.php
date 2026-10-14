<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Inquiry;

use Nova\Core\Environment;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Inquiry\FillToken;
use Nova\Inquiry\InquiryConfig;
use Nova\Inquiry\InquiryEndpoint;
use Nova\Inquiry\InquiryNotifier;
use Nova\Inquiry\InquiryRecipient;
use Nova\Inquiry\InquiryRetention;
use Nova\Inquiry\InquiryView;
use Nova\Inquiry\LiveIndex;
use Nova\Publish\AssetBuilder;
use Nova\Publish\BuildConfig;
use Nova\Publish\ChangeTracking;
use Nova\Publish\Initiator;
use Nova\Publish\PublishEngine;
use Nova\Publish\PublishJournal;
use Nova\Publish\SiteBuilder;
use Nova\Publish\SymlinkSwitcher;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use Nova\Tests\Integration\Publish\TestClock;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The inquiry endpoint in process (05 §10, §13; E-12, E-21), against a real
 * release published into a temporary site directory and the disposable test
 * database. Test data only (synthetic QZ designations); mail goes to a
 * recording double, never out.
 */
final class InquiryEndpointTest extends TestCase
{
    private const string KEY = 'test-inquiry-key-0123456789abcdef0123456789';
    private const string FA = 'fa.example.test';
    private const string EN = 'en.example.test';

    private static string $assets;
    private string $tmp;
    private CatalogueFixture $f;
    private TestClock $clock;
    private RecordingMailer $mailer;
    private BuildConfig $build;
    private bool $storageDown = false;
    private string $errorLog = '';

    public static function setUpBeforeClass(): void
    {
        self::$assets = sys_get_temp_dir() . '/nova-inq-assets-' . bin2hex(random_bytes(4));
        AssetBuilder::build(Paths::fromHere()->root . '/assets-src', self::$assets);
    }

    public static function tearDownAfterClass(): void
    {
        Files::removeTree(self::$assets);
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-inq-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/site', 0775, true);
        // The endpoint logs expected failures privately; keep them out of the test output.
        $this->errorLog = (string) ini_set('error_log', $this->tmp . '/error.log');
        $this->f = new CatalogueFixture();
        $this->clock = new TestClock(1_800_000_000);
        $this->mailer = new RecordingMailer();
        $this->build = new BuildConfig(['fa' => 'https://' . self::FA, 'en' => 'https://' . self::EN], false, true);

        $f = $this->f;
        $qz1 = $f->products->createProduct($f->owner, $f->family, 'QZ 1');
        $listed = $f->products->addVariant($f->owner, $qz1, 'QZ 1-2RS', $f->source, true);
        $unlisted = $f->products->addVariant($f->owner, $qz1, 'QZ 1-ZZ', $f->source, false);
        $qz2 = $f->products->createProduct($f->owner, $f->family, 'QZ 2');
        $qz2k = $f->products->addVariant($f->owner, $qz2, 'QZ 2 K', $f->source, true);
        $f->products->addSpelling($f->owner, $qz1, null, 'QZ ONE');
        Sql::run($f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$qz1]);
        Sql::run($f->pdo, "UPDATE product SET status = 'published', base_listed = 0 WHERE id = ?", [$qz2]);
        Sql::run($f->pdo, "UPDATE product_variant SET status = 'published' WHERE id IN (?, ?, ?)", [$listed, $unlisted, $qz2k]);
        (new InquiryRecipient($f->pdo))->set($f->owner, 'owner-inbox@test.invalid');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        Files::removeTree($this->tmp);
    }

    private function engine(): PublishEngine
    {
        $root = Paths::fromHere()->root;

        return new PublishEngine($this->f->pdo, new SymlinkSwitcher($this->tmp . '/site'), new PublishJournal($this->tmp . '/journal.json'), new SiteBuilder($root . '/app', self::$assets, $this->build), $this->build, Environment::Production, $root . '/app/i18n/contact.php');
    }

    private function publish(): string
    {
        $result = $this->engine()->publish(Initiator::admin($this->f->owner));
        self::assertSame('live', $result->status, implode('; ', $result->blockers));

        self::assertNotNull($result->release);

        return $result->release;
    }

    private function endpoint(int $perHour = 10): InquiryEndpoint
    {
        $root = Paths::fromHere()->root;
        $pdo = $this->f->pdo;
        $mailer = $this->mailer;
        $site = $this->tmp . '/site';

        return new InquiryEndpoint(
            $this->build,
            new InquiryConfig(self::KEY, 3, 86_400, $perHour, 30),
            fn(): PDO => $this->storageDown ? throw new RuntimeException('database unreachable (test)') : $pdo,
            static fn(): ?array => (new LiveIndex(new SymlinkSwitcher($site)))->load(),
            static fn(PDO $pdo): InquiryNotifier => new InquiryNotifier($pdo, $mailer, 'https://' . self::FA),
            new InquiryView($root . '/app', self::$assets, $this->build, ['tel1' => '+989100000000', 'tel2' => '+989100000001', 'land1' => '+982100000000', 'land2' => '+982100000001', 'wa' => '989100000000'], false),
            $this->clock,
        );
    }

    private function token(string $lang = 'fa', int $age = 10): string
    {
        return (new FillToken(self::KEY))->issue($lang, $this->clock->now() - $age);
    }

    /** @param array<string, string> $post */
    private function post(array $post, bool $json = true, string $host = self::FA, ?string $fetchSite = 'same-origin', ?string $origin = null, string $address = '198.51.100.7', ?InquiryEndpoint $endpoint = null): Response
    {
        $post += ['name' => 'Test Visitor', 'phone' => '0912 000 0000', 'token' => $this->token($host === self::EN ? 'en' : 'fa')];

        return ($endpoint ?? $this->endpoint())->handle(new Request('POST', InquiryEndpoint::PATH, $host, [], $post, $address, $origin, $fetchSite, $json ? 'application/json' : 'text/html'));
    }

    /** @return array<string, mixed> */
    private static function body(Response $r): array
    {
        $data = json_decode($r->body, true);
        self::assertIsArray($data, $r->body);

        /** @var array<string, mixed> */
        return $data;
    }

    /** @return array<string, mixed> */
    private function lastItem(): array
    {
        $row = Sql::row($this->f->pdo, 'SELECT q.release_id, q.match_state, q.notify_status, i.* FROM inquiry q LEFT JOIN inquiry_item i ON i.inquiry_id = q.id ORDER BY q.id DESC LIMIT 1');
        self::assertNotNull($row);

        return $row;
    }

    private function inquiries(): int
    {
        return Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM inquiry');
    }

    // ---- successful submission, exact designation, server-side match

    public function testAStoredInquiryKeepsTheExactDesignationAndTheServerMatch(): void
    {
        $release = $this->publish();
        $unpublished = $this->unpublishedCount();
        $r = $this->post(['code' => "  QZ 1-2RS\u{00A0}", 'qty' => '40', 'msg' => "Line 1\nLine 2", 'product' => 'qz-1', 'item' => 'qz-1-2rs', 'match' => 'exact_variant']);

        self::assertSame(201, $r->status, $r->body);
        self::assertSame(['ok' => true], self::body($r));
        $item = $this->lastItem();
        self::assertSame('QZ 1-2RS', $item['requested_text'], 'trimmed only, suffix kept verbatim');
        self::assertSame('qz12rs', $item['requested_key']);
        self::assertSame('exact_variant', $item['match_type']);
        self::assertSame('p:qz-1', $item['product_ref']);
        self::assertSame('v:qz-1-2rs', $item['variant_ref']);
        self::assertSame('QZ 1-2RS', $item['variant_designation']);
        self::assertSame('v:qz-1-2rs', $item['chosen_ref']);
        self::assertSame(0, self::int($item['context_differs']));
        self::assertSame($release, $item['release_id']);
        self::assertSame('matched', $item['match_state']);
        $q = Sql::row($this->f->pdo, 'SELECT name, phone, quantity, message, lang, status FROM inquiry');
        self::assertSame(['name' => 'Test Visitor', 'phone' => '0912 000 0000', 'quantity' => '40', 'message' => "Line 1\nLine 2", 'lang' => 'fa', 'status' => 'new'], $q);

        // Notified after storing, without personal data.
        self::assertSame('sent', $item['notify_status']);
        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertSame('owner-inbox@test.invalid', $mail['to']);
        self::assertStringContainsString('QZ 1-2RS — exact_variant', $mail['body']);
        self::assertStringContainsString("Matched against release: {$release}", $mail['body']);
        foreach (['Test Visitor', '0912', 'Line 1'] as $personal) {
            self::assertStringNotContainsString($personal, $mail['body'] . $mail['subject']);
        }
        // The audit trail has no visitor content, and an inquiry is not an unpublished change.
        $audit = (string) Sql::scalar($this->f->pdo, "SELECT CONCAT(entity_label, ' ', after_text) FROM audit_event WHERE action = 'inquiry.received'");
        self::assertStringContainsString('IQ-', $audit);
        self::assertStringNotContainsString('Test Visitor', $audit);
        self::assertStringNotContainsString('0912', $audit);
        self::assertSame($unpublished, $this->unpublishedCount());
    }

    public function testSuffixesAndSpellingsAreNeverRewrittenOrCollapsed(): void
    {
        $this->publish();
        $cases = [
            'qz1-2rs' => ['exact_variant', 'v:qz-1-2rs', null],
            'QZ 1-ZZ' => ['unlisted_variant', 'v:qz-1-zz', null],
            'QZ 1-2RSH' => ['none', null, null],      // a different brand notation stays distinct (V-4)
            'QZ 1/C3' => ['none', null, null],        // no suffix rule is approved (V-1); "/" stays significant (E-19)
            'QZ ONE' => ['exact_product', null, 'QZ ONE'],
            'QZ 2' => ['related', null, null],
        ];
        foreach ($cases as $code => [$type, $variant, $spelling]) {
            self::assertSame(201, $this->post(['code' => $code], address: '198.51.100.' . \strlen($code))->status, $code);
            $item = $this->lastItem();
            self::assertSame($code, $item['requested_text'], $code);
            self::assertSame($type, $item['match_type'], $code);
            self::assertSame($variant, $item['variant_ref'], $code);
            self::assertSame($spelling, $item['spelling'], $code);
        }
        self::assertSame('["QZ 2 K"]', $this->lastItem()['listed_text'], 'related names the listed items as the page did');
    }

    public function testATamperedMatchContextIsNeverTrusted(): void
    {
        $this->publish();
        // Unknown code dressed up as an exact listed variant.
        $this->post(['code' => 'QZ 9', 'product' => 'qz-1', 'item' => 'qz-1-2rs', 'match' => 'exact_variant']);
        $item = $this->lastItem();
        self::assertSame('none', $item['match_type']);
        self::assertNull($item['product_ref']);
        self::assertNull($item['chosen_ref']);
        self::assertSame(1, self::int($item['context_differs']));

        // A related match: a choice outside the server's listed items is dropped, one of them is kept.
        $this->post(['code' => 'QZ 2', 'product' => 'qz-2', 'item' => 'qz-1-2rs', 'match' => 'related'], address: '198.51.100.8');
        $item = $this->lastItem();
        self::assertSame(['related', null, 1], [$item['match_type'], $item['chosen_ref'], self::int($item['context_differs'])]);
        $this->post(['code' => 'QZ 2', 'product' => 'qz-2', 'item' => 'qz-2-k', 'match' => 'related'], address: '198.51.100.9');
        $item = $this->lastItem();
        self::assertSame(['related', 'v:qz-2-k', 'QZ 2 K', 0], [$item['match_type'], $item['chosen_ref'], $item['chosen_designation'], self::int($item['context_differs'])]);

        // An exact variant cannot be re-pointed at another item.
        $this->post(['code' => 'QZ 1-2RS', 'product' => 'qz-2', 'item' => 'qz-2-k', 'match' => 'exact_product'], address: '198.51.100.10');
        $item = $this->lastItem();
        self::assertSame(['exact_variant', 'p:qz-1', 'v:qz-1-2rs', 1], [$item['match_type'], $item['product_ref'], $item['chosen_ref'], self::int($item['context_differs'])]);
    }

    // ---- release changes

    public function testTheMatchFollowsTheLiveReleaseNotTheWorkingCatalogue(): void
    {
        $first = $this->publish();
        $qz3 = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 3');
        Sql::run($this->f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$qz3]);

        $this->post(['code' => 'QZ 3'], address: '198.51.100.11');
        self::assertSame(['none', $first], [$this->lastItem()['match_type'], $this->lastItem()['release_id']], 'saved but unpublished: not in the live release');

        $second = $this->publish();
        $this->post(['code' => 'QZ 3'], address: '198.51.100.12');
        self::assertSame(['exact_product', $second, 'p:qz-3'], [$this->lastItem()['match_type'], $this->lastItem()['release_id'], $this->lastItem()['product_ref']]);

        $this->engine()->rollback(Initiator::admin($this->f->owner));
        $this->post(['code' => 'QZ 3'], address: '198.51.100.13');
        self::assertSame(['none', $first], [$this->lastItem()['match_type'], $this->lastItem()['release_id']], 'after a rollback the earlier release decides');
    }

    public function testWithoutAReadableReleaseTheInquiryIsStillStoredVerbatim(): void
    {
        $this->post(['code' => 'QZ 1-2RS'], address: '198.51.100.14');
        $item = $this->lastItem();
        self::assertSame(['no_release', null, null, 'QZ 1-2RS'], [$item['match_state'], $item['release_id'], $item['match_type'], $item['requested_text']]);

        $release = $this->publish();
        file_put_contents($this->tmp . "/site/releases/{$release}/" . LiveIndex::FILE, '{"release":"' . $release . '","index":{"products":[]}}');
        $this->post(['code' => 'QZ 1-2RS'], address: '198.51.100.15');
        $item = $this->lastItem();
        self::assertSame(['unavailable', null, null], [$item['match_state'], $item['release_id'], $item['match_type']], 'a file that does not match the manifest is not trusted');
        self::assertSame(2, $this->inquiries());
    }

    public function testAReleaseBuiltBeforeStep10IsMatchedFromItsEmbeddedIndex(): void
    {
        $release = $this->publish();
        $dir = $this->tmp . "/site/releases/{$release}";
        unlink($dir . '/' . LiveIndex::FILE);
        $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['files']);
        unset($manifest['files'][LiveIndex::FILE]);
        file_put_contents($dir . '/manifest.json', json_encode($manifest));

        $this->post(['code' => 'QZ 1-2RS']);
        self::assertSame(['matched', 'exact_variant', $release], [$this->lastItem()['match_state'], $this->lastItem()['match_type'], $this->lastItem()['release_id']]);
    }

    // ---- invalid input

    public function testInvalidInputIsRefusedWithReasonsAndNothingIsStored(): void
    {
        $this->publish();
        $r = $this->post(['name' => '', 'phone' => '12', 'code' => str_repeat('6', 65), 'msg' => '']);
        self::assertSame(422, $r->status);
        self::assertEquals(['name' => 'required', 'phone' => 'phone', 'code' => 'too_long'], self::body($r)['fields']);

        $r = $this->post(['code' => '', 'msg' => '   ']);
        self::assertSame(['msg' => 'code_or_message'], self::body($r)['fields'], 'a code or a description of the application');
        $r = $this->post(['name' => "Name\u{0007}", 'code' => 'QZ 1']);
        self::assertSame(['name' => 'invalid'], self::body($r)['fields']);
        $r = $this->post(['code' => "\xC3\x28"]);
        self::assertSame([400, 'rejected'], [$r->status, self::body($r)['error']], 'invalid UTF-8');
        $r = $this->post(['phone' => '+98 (912) ۱۲۳-۴۵۶۷', 'code' => 'QZ 1']);
        self::assertSame(201, $r->status, 'Persian digits and separators are a phone number');
        self::assertSame(1, $this->inquiries());
    }

    public function testWithoutJavaScriptTheFormComesBackFilledInWithTheReasons(): void
    {
        $this->publish();
        $r = $this->post(['name' => '', 'phone' => '0912 000 0000', 'code' => 'QZ 1-2RS<b>', 'msg' => 'پیام "آزمایشی"'], json: false);
        self::assertSame(422, $r->status);
        self::assertStringStartsWith('text/html', $r->headers['Content-Type']);
        self::assertSame(InquiryEndpoint::CSP, $r->headers['Content-Security-Policy']);
        self::assertStringContainsString('<html lang="fa" dir="rtl"', $r->body);
        self::assertStringContainsString('value="QZ 1-2RS&lt;b&gt;"', $r->body, 'the entered code, escaped, unchanged');
        self::assertStringContainsString('پیام &quot;آزمایشی&quot;</textarea>', $r->body);
        self::assertStringContainsString('aria-invalid="true" aria-describedby="f-name-err"', $r->body);
        self::assertStringContainsString('این مورد لازم است.', $r->body);
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $r->body);

        $r = $this->post(['phone' => 'abc', 'code' => 'QZ 1'], json: false, host: self::EN);
        self::assertStringContainsString('<html lang="en" dir="ltr"', $r->body);
        self::assertStringContainsString('Enter a phone number with 7 to 15 digits.', $r->body);
        self::assertSame(0, $this->inquiries());
    }

    public function testAJavaScriptFreeSubmissionIsConfirmedThenStored(): void
    {
        $this->publish();
        // The static page carries no token: the first POST returns the form with the values and a token.
        $r = $this->post(['code' => 'QZ 1-2RS', 'msg' => 'no script', 'token' => ''], json: false);
        self::assertSame(409, $r->status);
        self::assertStringContainsString('value="QZ 1-2RS"', $r->body);
        self::assertSame(1, preg_match('/name="token" value="(v1\.[0-9]+\.[0-9a-f]{64})"/', $r->body, $m));
        self::assertArrayHasKey(1, $m);
        self::assertSame(0, $this->inquiries());

        $this->clock->time += 5;
        $r = $this->post(['code' => 'QZ 1-2RS', 'msg' => 'no script', 'token' => (string) ($m[1] ?? '')], json: false);
        self::assertSame(303, $r->status);
        self::assertSame('/api/inquiry.php?sent=1', $r->headers['Location']);
        self::assertSame(1, $this->inquiries());
        $page = $this->endpoint()->handle(new Request('GET', InquiryEndpoint::PATH, self::EN, ['sent' => '1']));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Your inquiry was received.', $page->body);
    }

    // ---- abuse

    public function testCrossOriginRequestsAreRefused(): void
    {
        $this->publish();
        self::assertSame(403, $this->post(['code' => 'QZ 1'], fetchSite: 'cross-site')->status);
        self::assertSame(403, $this->post(['code' => 'QZ 1'], fetchSite: 'same-site')->status, 'the other language host is another origin');
        self::assertSame(403, $this->post(['code' => 'QZ 1'], fetchSite: null, origin: 'https://evil.test')->status);
        self::assertSame(403, $this->post(['code' => 'QZ 1'], fetchSite: null, origin: 'https://' . self::EN)->status);
        self::assertSame(403, $this->post(['code' => 'QZ 1'], fetchSite: null, origin: 'null')->status, 'Origin: null proves nothing');
        self::assertSame(403, $this->post(['code' => 'QZ 1'], fetchSite: null)->status, 'no proof at all');
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM inquiry_attempt'), 'refused before anything is written');
        self::assertSame(201, $this->post(['code' => 'QZ 1'], fetchSite: null, origin: 'https://' . self::FA)->status, 'own Origin, older browser');
        self::assertSame(404, $this->post(['code' => 'QZ 1'], host: 'evil.test')->status);
    }

    public function testHoneypotAndFillTimeRejectAutomatedSubmissions(): void
    {
        $this->publish();
        $r = $this->post(['code' => 'QZ 1', 'website' => 'https://spam.test']);
        self::assertSame([400, 'rejected'], [$r->status, self::body($r)['error']]);

        $r = $this->post(['code' => 'QZ 1', 'token' => '']);
        self::assertSame(409, $r->status);
        $body = self::body($r);
        self::assertSame('token', $body['error']);
        self::assertSame(3, $body['retry_after']);
        self::assertIsString($body['token']);

        $r = $this->post(['code' => 'QZ 1', 'token' => $this->token(age: 1)]);
        self::assertSame(['too_fast', 2], [self::body($r)['error'], self::body($r)['retry_after']]);
        $forged = (new FillToken('another-key-0123456789abcdef0123456789abcdef'))->issue('fa', $this->clock->now() - 60);
        self::assertSame('token', self::body($this->post(['code' => 'QZ 1', 'token' => $forged]))['error'], 'not signed with this key');
        self::assertSame('token', self::body($this->post(['code' => 'QZ 1', 'token' => $this->token('en')]))['error'], 'issued for the other language');
        self::assertSame('token', self::body($this->post(['code' => 'QZ 1', 'token' => $this->token(age: 90_000)]))['error'], 'expired');
        self::assertSame(0, $this->inquiries());
        self::assertSame(['honeypot' => 1, 'token' => 5], array_map(intval(...), Sql::pairs($this->f->pdo, 'SELECT outcome, COUNT(*) FROM inquiry_attempt GROUP BY outcome ORDER BY outcome')));
    }

    public function testTheRateLimitIsPerAddressAndKeepsOnlyAHashFor24Hours(): void
    {
        $this->publish();
        $endpoint = $this->endpoint(perHour: 3);
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(201, $this->post(['code' => 'QZ 1'], endpoint: $endpoint)->status);
        }
        $r = $this->post(['code' => 'QZ 1'], endpoint: $endpoint);
        self::assertSame([429, 'throttled', '3600'], [$r->status, self::body($r)['error'], $r->headers['Retry-After']]);
        self::assertSame(201, $this->post(['code' => 'QZ 1'], address: '203.0.113.9', endpoint: $endpoint)->status, 'another address is unaffected');

        $stored = Sql::column($this->f->pdo, 'SELECT DISTINCT address_hash FROM inquiry_attempt');
        self::assertCount(2, $stored);
        foreach ($stored as $hash) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash);
            self::assertNotSame(hash('sha256', '198.51.100.7'), $hash, 'keyed, not a bare hash of the address');
        }
        self::assertSame(0, Sql::int($this->f->pdo, "SELECT COUNT(*) FROM inquiry_attempt WHERE address_hash LIKE '%198.51%'"));

        Sql::run($this->f->pdo, 'UPDATE inquiry_attempt SET attempted_at = UTC_TIMESTAMP(6) - INTERVAL 25 HOUR');
        self::assertSame(201, $this->post(['code' => 'QZ 1'], endpoint: $endpoint)->status, 'the window has passed');
        self::assertSame(1, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM inquiry_attempt'), 'hashes older than 24 hours were deleted');
    }

    // ---- SMTP failure and retry

    public function testAnSmtpFailureNeverLosesTheInquiryAndRetrySendsItOnce(): void
    {
        $this->publish();
        $this->mailer->fail = true;
        $r = $this->post(['code' => 'QZ 1-2RS']);
        self::assertSame(201, $r->status, 'the visitor is told it was received: it is stored');
        $row = Sql::row($this->f->pdo, 'SELECT id, notify_status, notify_attempts, notify_error FROM inquiry');
        self::assertNotNull($row);
        self::assertSame(['failed', 1], [$row['notify_status'], (int) $row['notify_attempts']]);
        self::assertStringContainsString('SMTP: connect() failed', (string) $row['notify_error']);
        self::assertSame(1, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM inquiry_item'));

        $notifier = new InquiryNotifier($this->f->pdo, $this->mailer, 'https://' . self::FA);
        self::assertSame(['sent' => 0, 'failed' => 1], $notifier->retryAll());
        $this->mailer->fail = false;
        self::assertSame(['sent' => 1, 'failed' => 0], $notifier->retryAll());
        self::assertSame('skipped', $notifier->deliver((int) $row['id']), 'already sent: never twice');
        self::assertCount(1, $this->mailer->sent);
        self::assertSame(['sent', 3], [Sql::scalar($this->f->pdo, 'SELECT notify_status FROM inquiry'), Sql::int($this->f->pdo, 'SELECT notify_attempts FROM inquiry')]);
    }

    public function testAClaimedSendIsNotDuplicatedUntilItIsStale(): void
    {
        $this->publish();
        $this->mailer->fail = true;
        $this->post(['code' => 'QZ 1']);
        $id = (int) Sql::scalar($this->f->pdo, 'SELECT id FROM inquiry');
        $this->mailer->fail = false;
        Sql::run($this->f->pdo, "UPDATE inquiry SET notify_status = 'sending', notify_claimed_at = UTC_TIMESTAMP(6) WHERE id = ?", [$id]);
        $notifier = new InquiryNotifier($this->f->pdo, $this->mailer, 'https://' . self::FA);
        self::assertSame('skipped', $notifier->deliver($id), 'another request holds the send');
        Sql::run($this->f->pdo, 'UPDATE inquiry SET notify_claimed_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE id = ?', [$id]);
        self::assertSame('sent', $notifier->deliver($id), 'a crashed claim expires');
    }

    public function testWithoutARecipientTheNotificationFailsVisiblyAndOnlyTheOwnerSetsIt(): void
    {
        $this->publish();
        $recipient = new InquiryRecipient($this->f->pdo);
        $recipient->set($this->f->owner, '');
        $this->post(['code' => 'QZ 1']);
        self::assertSame('failed', Sql::scalar($this->f->pdo, 'SELECT notify_status FROM inquiry'));
        self::assertStringContainsString('No notification address', (string) Sql::scalar($this->f->pdo, 'SELECT notify_error FROM inquiry'));

        try {
            $recipient->set($this->f->editor, 'editor@test.invalid');
            self::fail('an Editor changed the recipient');
        } catch (\Nova\Catalogue\CatalogueError $e) {
            self::assertSame('forbidden', $e->reason);
        }
        foreach (['not-an-email', 'a@b.test, c@d.test', "a@b.test\r\nBcc: x@y.test"] as $bad) {
            try {
                $recipient->set($this->f->owner, $bad);
                self::fail("accepted {$bad}");
            } catch (\Nova\Catalogue\CatalogueError $e) {
                self::assertSame('invalid', $e->reason);
            }
        }
        $recipient->set($this->f->owner, 'sales@test.invalid');
        self::assertSame('sales@test.invalid', $recipient->get());
        self::assertSame(['sent' => 1, 'failed' => 0], (new InquiryNotifier($this->f->pdo, $this->mailer, 'https://' . self::FA))->retryAll());
        self::assertSame('sales@test.invalid', $this->mailer->sent[0]['to']);
    }

    public function testStorageFailureKeepsTheValuesForTheVisitor(): void
    {
        $this->publish();
        $this->storageDown = true;
        $r = $this->post(['code' => 'QZ 1-2RS', 'msg' => 'keep me']);
        self::assertSame([503, 'unavailable'], [$r->status, self::body($r)['error']]);
        $r = $this->post(['code' => 'QZ 1-2RS', 'msg' => 'keep me'], json: false);
        self::assertSame(503, $r->status);
        self::assertStringContainsString('value="QZ 1-2RS"', $r->body);
        self::assertStringContainsString('keep me</textarea>', $r->body);
        self::assertStringContainsString('https://wa.me/989100000000', $r->body, 'WhatsApp and phone stay available');
    }

    // ---- retention, routing

    public function testRetentionDeletesAfter24MonthsAndOnRequest(): void
    {
        $this->publish();
        $this->post(['code' => 'QZ 1'], address: '198.51.100.21');
        $this->post(['code' => 'QZ 2'], address: '198.51.100.22');
        $this->post(['code' => 'QZ 1-2RS'], address: '198.51.100.23');
        $ids = array_map(intval(...), Sql::column($this->f->pdo, 'SELECT id FROM inquiry ORDER BY id'));
        Sql::run($this->f->pdo, 'UPDATE inquiry SET received_at = UTC_TIMESTAMP(6) - INTERVAL 25 MONTH WHERE id = ?', [$ids[0]]);
        Sql::run($this->f->pdo, 'UPDATE inquiry SET received_at = UTC_TIMESTAMP(6) - INTERVAL 23 MONTH WHERE id = ?', [$ids[1]]);

        $retention = new InquiryRetention($this->f->pdo);
        self::assertSame(1, $retention->purgeExpired());
        self::assertSame([$ids[1], $ids[2]], array_map(intval(...), Sql::column($this->f->pdo, 'SELECT id FROM inquiry ORDER BY id')));
        self::assertSame(0, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM inquiry_item WHERE inquiry_id = ?', [$ids[0]]));

        try {
            $retention->delete($this->f->editor, $ids[1]);
            self::fail('an Editor deleted an inquiry');
        } catch (\Nova\Catalogue\CatalogueError $e) {
            self::assertSame('forbidden', $e->reason);
        }
        self::assertTrue($retention->delete($this->f->owner, $ids[1]));
        self::assertSame(1, $this->inquiries());
        $audit = implode(' ', array_map(strval(...), Sql::column($this->f->pdo, "SELECT CONCAT(action, ':', COALESCE(after_text, '')) FROM audit_event WHERE action IN ('inquiry.purged', 'inquiry.deleted')")));
        self::assertStringContainsString('inquiry.purged:{"count":1', $audit);
        self::assertStringContainsString('inquiry.deleted:', $audit);
        self::assertStringNotContainsString('Test Visitor', $audit);
    }

    public function testRoutingTokensAndMethods(): void
    {
        $endpoint = $this->endpoint();
        $r = $endpoint->handle(new Request('GET', InquiryEndpoint::PATH, self::FA, ['token' => '1']));
        self::assertSame(200, $r->status);
        $token = self::body($r)['token'];
        self::assertIsString($token);
        self::assertSame($this->clock->now(), (new FillToken(self::KEY))->issuedAt($token, 'fa'));
        self::assertNull((new FillToken(self::KEY))->issuedAt($token, 'en'));
        self::assertSame('no-store', $r->headers['Cache-Control']);
        self::assertSame(303, $endpoint->handle(new Request('GET', InquiryEndpoint::PATH, self::FA))->status);
        self::assertSame(405, $endpoint->handle(new Request('PUT', InquiryEndpoint::PATH, self::FA))->status);
        self::assertSame(404, $endpoint->handle(new Request('GET', InquiryEndpoint::PATH, 'admin.example.test', ['token' => '1']))->status);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : -1;
    }

    private function unpublishedCount(): int
    {
        [$sql, $params] = ChangeTracking::notLive($this->f->pdo);

        return Sql::int($this->f->pdo, "SELECT COUNT(*) FROM audit_event a WHERE {$sql}", $params);
    }
}
