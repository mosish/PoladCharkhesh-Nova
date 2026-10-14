<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Publish\BuildConfig;
use Nova\Publish\Clock;
use PDO;
use Throwable;

/**
 * The inquiry endpoint, the only public PHP (D1, 05 §13): a same-origin POST
 * to /api/inquiry.php on both language hosts, with and without JavaScript.
 *
 * Order of work:
 * 1. the host decides the language (the form's own `lang` field is ignored);
 * 2. abuse checks: same origin, per-address rate limit, honeypot, minimum
 *    fill time (a signed token, `FillToken`);
 * 3. validation (`InquiryForm`);
 * 4. the designation match, recomputed against the live release (E-21);
 * 5. the inquiry, its items, match and release stored in one transaction;
 * 6. the notification, which can fail without losing anything;
 * 7. the response: JSON for the page's script, otherwise a page or a 303.
 *
 * Every recoverable failure answers with the visitor's values intact: the
 * script keeps the form as it is, and without script the form comes back
 * filled in, with the reasons, in the visitor's language.
 */
final readonly class InquiryEndpoint
{
    public const string PATH = '/api/inquiry.php';
    public const string CSP = "default-src 'none'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";

    /**
     * @param \Closure(): PDO $pdo opened only when a submission needs it
     * @param \Closure(): ?array{release: string, index: \Nova\Designation\DesignationIndex} $live the live release's index (throws when unreadable)
     * @param \Closure(PDO): InquiryNotifier $notifier
     */
    public function __construct(
        private BuildConfig $build,
        private InquiryConfig $config,
        private \Closure $pdo,
        private \Closure $live,
        private \Closure $notifier,
        private InquiryView $view,
        private Clock $clock,
    ) {}

    public function handle(Request $r): Response
    {
        $lang = $this->language($r);
        if ($lang === null) {
            return new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($r->method === 'GET' || $r->method === 'HEAD') {
            return $this->get($r, $lang);
        }
        if (!$r->isPost()) {
            return new Response(405, '', ['Allow' => 'GET, POST']);
        }
        $json = str_contains($r->accept, 'application/json');

        if (!$this->sameOrigin($r, $lang)) {
            return $this->fail($json, $lang, 403, 'origin', null);
        }
        $form = InquiryForm::fromRequest($r);
        if ($form->malformed) {
            return $this->fail($json, $lang, 400, 'rejected', null);
        }

        try {
            $pdo = ($this->pdo)();
            $throttle = new Throttle($pdo, $this->config->key, $this->config->perHour, $this->config->perDay);
            $attempt = $throttle->admit($throttle->addressHash($r->clientAddress));
        } catch (Throwable $e) {
            error_log('Nova inquiry: storage unavailable: ' . $e::class . ': ' . $e->getMessage());

            return $this->fail($json, $lang, 503, 'unavailable', $form);
        }
        if ($attempt === null) {
            return $this->fail($json, $lang, 429, 'throttled', $form, ['Retry-After' => '3600']);
        }
        if ($form->honeypot !== '') {
            $throttle->finish($attempt, 'honeypot');

            return $this->fail($json, $lang, 400, 'rejected', $form);
        }

        $now = $this->clock->now();
        $issued = (new FillToken($this->config->key))->issuedAt($form->token, $lang);
        if ($issued === null || $issued > $now + 60 || $now - $issued > $this->config->maxTokenAge) {
            $throttle->finish($attempt, 'token');

            return $this->confirm($json, $lang, $form, 'token', (new FillToken($this->config->key))->issue($lang, $now), $this->config->minFillSeconds);
        }
        if ($now - $issued < $this->config->minFillSeconds) {
            $throttle->finish($attempt, 'token');

            return $this->confirm($json, $lang, $form, 'too_fast', $form->token, $this->config->minFillSeconds - ($now - $issued));
        }
        if (!$form->valid()) {
            $throttle->finish($attempt, 'invalid');
            if ($json) {
                return self::json(422, ['ok' => false, 'error' => 'invalid', 'fields' => $form->errors]);
            }

            return $this->page(422, $lang, ['form' => $this->formVars($form, $form->token), 'notice' => 'invalid']);
        }

        [$state, $release, $items] = $this->match($form);
        try {
            $id = (new InquiryStore($pdo))->store($lang, $form->values, $state, $release, $items);
        } catch (Throwable $e) {
            error_log('Nova inquiry: not stored: ' . $e::class . ': ' . $e->getMessage());
            try {
                $throttle->finish($attempt, 'failed');
            } catch (Throwable) {
            }

            return $this->fail($json, $lang, 503, 'unavailable', $form);
        }
        $throttle->finish($attempt, 'stored');

        // Stored: nothing below can lose the inquiry or change the answer.
        try {
            ($this->notifier)($pdo)->deliver($id);
        } catch (Throwable $e) {
            error_log('Nova inquiry: notification for ' . InquiryStore::reference($id) . ' not attempted: ' . $e->getMessage());
        }
        try {
            (new InquiryRetention($pdo))->purgeExpired(50);
        } catch (Throwable $e) {
            error_log('Nova inquiry: retention purge failed: ' . $e->getMessage());
        }

        return $json ? self::json(201, ['ok' => true]) : new Response(303, '', ['Location' => self::PATH . '?sent=1']);
    }

    /** The language of the host the request came to (D6), or null for any other host. */
    public function language(Request $r): ?string
    {
        foreach ($this->build->hosts as $lang => $origin) {
            $host = parse_url($origin, PHP_URL_HOST);
            if (\is_string($host) && strtolower($host) === $r->hostname()) {
                return $lang;
            }
        }

        return null;
    }

    /**
     * Same origin (05 §13, §15). Fetch metadata decides when the browser sends
     * it; otherwise the Origin header must be this host's exact origin. A
     * request that proves neither is refused: modern browsers send at least
     * one on every POST (`Origin: null` alone is not proof).
     */
    public function sameOrigin(Request $r, string $lang): bool
    {
        if ($r->fetchSite !== null) {
            return $r->fetchSite === 'same-origin';
        }

        return $r->origin !== null && strtolower(rtrim($r->origin, '/')) === strtolower($this->build->hosts[$lang === 'en' ? 'en' : 'fa']);
    }

    private function get(Request $r, string $lang): Response
    {
        if (isset($r->query['token'])) {
            // No database, nothing stored: only a signed start time.
            return self::json(200, ['token' => (new FillToken($this->config->key))->issue($lang, $this->clock->now())]);
        }
        if ($r->queryString('sent') === '1') {
            return $this->page(200, $lang, ['sent' => true]);
        }

        return new Response(303, '', ['Location' => '/#contact']);
    }

    /**
     * @return array{'matched'|'no_code'|'no_release'|'unavailable', ?string, list<InquiryItem>}
     */
    private function match(InquiryForm $form): array
    {
        $code = $form->values['code'];
        if ($code === '') {
            return ['no_code', null, []];
        }
        try {
            $live = ($this->live)();
        } catch (Throwable $e) {
            error_log('Nova inquiry: live release unreadable, stored unmatched: ' . $e->getMessage());

            return ['unavailable', null, [InquiryItem::unmatched($code)]];
        }
        if ($live === null) {
            return ['no_release', null, [InquiryItem::unmatched($code)]];
        }

        return ['matched', $live['release'], [InquiryItem::match($live['index'], $code, $form->claimed)]];
    }

    /** Missing/expired token or too fast: nothing stored; the form returns with a token to confirm. */
    private function confirm(bool $json, string $lang, InquiryForm $form, string $reason, string $token, int $wait): Response
    {
        if ($json) {
            return self::json(409, ['ok' => false, 'error' => $reason, 'token' => $token, 'retry_after' => max(0, $wait)]);
        }

        return $this->page(409, $lang, ['form' => $this->formVars($form, $token), 'notice' => 'confirm']);
    }

    /**
     * @param 'origin'|'rejected'|'throttled'|'unavailable' $reason
     * @param array<string, string> $headers
     */
    private function fail(bool $json, string $lang, int $status, string $reason, ?InquiryForm $form, array $headers = []): Response
    {
        if ($json) {
            $r = self::json($status, ['ok' => false, 'error' => $reason]);
            $r->headers = $headers + $r->headers;

            return $r;
        }
        $vars = ['notice' => $reason, 'form' => $form === null || $reason === 'origin' ? null : $this->formVars($form, (new FillToken($this->config->key))->issue($lang, $this->clock->now()))];
        $r = $this->page($status, $lang, $vars);
        $r->headers = $headers + $r->headers;

        return $r;
    }

    /** @return array<string, mixed> */
    private function formVars(InquiryForm $form, string $token): array
    {
        return ['values' => $form->values, 'errors' => $form->errors, 'claimed' => $form->claimed, 'token' => $token, 'limits' => InquiryForm::LIMITS];
    }

    /** @param array<string, mixed> $vars */
    private function page(int $status, string $lang, array $vars): Response
    {
        return new Response($status, $this->view->render($lang, $vars + ['sent' => false, 'notice' => null, 'form' => null]), [
            'Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => self::CSP,
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): Response
    {
        return new Response($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/json; charset=utf-8', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ]);
    }
}
