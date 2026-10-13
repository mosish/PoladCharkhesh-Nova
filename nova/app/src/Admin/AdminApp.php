<?php

declare(strict_types=1);

namespace Nova\Admin;

use Nova\Catalogue\CatalogueError;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Publish\Clock;
use Nova\Publish\Initiator;
use Nova\Publish\PublishEngine;
use Nova\Publish\SystemClock;
use Nova\Security\Actor;
use Nova\Security\AdminAuth;
use Nova\Security\Csrf;
use Nova\Security\SessionStore;
use Nova\Security\Totp;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The Admin (05 §6, D11): server-rendered, Persian RTL, at /admin on the .ir
 * host only. Request flow: host check → session → (POST) same-origin check
 * and CSRF token → sign-in → role policy → the domain service (validation,
 * transaction, audit) → redirect with a confirmation message. An interrupted
 * publish is finished or rolled back at the start of every signed-in request
 * (05 §7).
 */
final class AdminApp
{
    private readonly AdminAuth $auth;
    private readonly Csrf $csrf;
    private readonly ProductAdmin $products;

    /**
     * @param \Closure(): PublishEngine $engine
     * @param \Closure(int, string): ?string $preview renders a draft Product page (id, language) or null
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SessionStore $session,
        private readonly AdminView $view,
        private readonly string $adminHost,
        private readonly \Closure $engine,
        private readonly PublishAdmin $publish,
        private readonly \Closure $preview,
        private readonly string $siteUrl,
        Clock $clock = new SystemClock(),
    ) {
        $this->auth = new AdminAuth($pdo, $session, $clock);
        $this->csrf = new Csrf($session);
        $this->products = new ProductAdmin($pdo);
    }

    public function handle(Request $r): Response
    {
        if ($r->hostname() !== $this->adminHost) {
            return new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($r->path === '/admin') {
            return Response::redirect('/admin/', 301);
        }
        if (!str_starts_with($r->path, '/admin/')) {
            return $this->page('error.html.twig', ['code' => 404], 404);
        }
        $this->session->start();

        if ($r->isPost()) {
            if (!$this->sameOrigin($r) || !$this->csrf->valid($r->input('_csrf'))) {
                return $this->page('error.html.twig', ['code' => 403, 'reason' => 'csrf'], 403);
            }
        } elseif ($r->method !== 'GET' && $r->method !== 'HEAD') {
            return new Response(405, '', ['Allow' => 'GET, POST']);
        }

        $path = substr($r->path, \strlen('/admin'));
        $public = match ($path) {
            '/login' => $this->login($r),
            '/login/totp' => $this->totp($r),
            '/login/enroll' => $this->enroll($r),
            default => null,
        };
        if ($public !== null) {
            return $public;
        }

        $actor = $this->auth->current();
        if ($actor === null) {
            return Response::redirect('/admin/login');
        }
        $this->recover();

        try {
            return $this->route($r, $path, $actor) ?? $this->page('error.html.twig', ['code' => 404] + $this->shell($actor, ''), 404);
        } catch (CatalogueError $e) {
            if ($e->reason === 'not_found' && !$r->isPost()) {
                return $this->page('error.html.twig', ['code' => 404] + $this->shell($actor, ''), 404);
            }
            if ($e->reason === 'forbidden') {
                return $this->page('error.html.twig', ['code' => 403, 'reason' => 'role'] + $this->shell($actor, ''), 403);
            }
            $this->flash('error', Messages::catalogue($e->reason), $e->getMessage());

            return Response::redirect($this->back($path));
        } catch (Throwable $e) {
            // Logged privately; the page stays generic (05 §15).
            error_log('Nova Admin: ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());

            return $this->page('error.html.twig', ['code' => 500] + $this->shell($actor, ''), 500);
        }
    }

    private function route(Request $r, string $path, Actor $actor): ?Response
    {
        $get = !$r->isPost();
        if ($path === '/') {
            return $get ? $this->dashboard($actor) : null;
        }
        if ($path === '/logout') {
            if ($get) {
                return null;
            }
            $this->auth->signOut();

            return Response::redirect('/admin/login');
        }
        if ($path === '/products') {
            return $get ? $this->productList($r, $actor) : $this->done($this->products->create($actor, $r), 'created');
        }
        if ($path === '/products/new') {
            return $get ? $this->page('product_new.html.twig', $this->shell($actor, 'products') + ['families' => $this->products->families()]) : null;
        }
        if ($path === '/sources' && !$get) {
            $this->products->addSource($actor, $r);
            $this->flash('ok', 'منبع افزوده شد و اکنون در فهرست منبع‌ها قابل انتخاب است.');

            return Response::redirect($this->back($r->input('return')));
        }
        if ($path === '/publish') {
            return $get ? $this->publishPage($actor) : $this->runPublish($actor);
        }
        if ($path === '/publish/rollback' && !$get) {
            return $this->runRollback($actor);
        }
        if (preg_match('#^/products/(\d+)(?:/(.+))?$#', $path, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];
        $action = $m[2] ?? '';

        if ($get) {
            if ($action === '') {
                return $this->editor($id, $actor);
            }
            if (preg_match('#^preview/(fa|en)$#', $action, $p) === 1) {
                $html = ($this->preview)($id, $p[1]);
                if ($html === null) {
                    return null;
                }
                // The publish templates as they are, at an Admin-only, noindex, uncached address (05 §6).
                return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"]);
            }

            return null;
        }

        $ownerOnly = ['variants', 'spellings', 'verify', 'standalone'];
        if (\in_array(explode('/', $action)[0], $ownerOnly, true) && !$actor->isOwner()) {
            return $this->page('error.html.twig', ['code' => 403, 'reason' => 'role'] + $this->shell($actor, ''), 403);
        }
        match (true) {
            $action === '' => $this->products->save($actor, $id, $r),
            $action === 'values' => $this->products->setValue($actor, $id, $r),
            preg_match('#^values/(\d+)/delete$#', $action, $v) === 1 => $this->products->removeValue($actor, $id, (int) $v[1], $r),
            $action === 'variants' => $this->products->addVariant($actor, $id, $r),
            preg_match('#^variants/(\d+)$#', $action, $v) === 1 => $this->products->updateVariant($actor, $id, (int) $v[1], $r),
            $action === 'spellings' => $this->products->addSpelling($actor, $id, $r),
            preg_match('#^spellings/(\d+)/delete$#', $action, $v) === 1 => $this->products->removeSpelling($actor, $id, (int) $v[1]),
            $action === 'description' => $this->products->saveDescription($actor, $id, $r),
            $action === 'verify' => $this->products->verify($actor, $id, $r),
            $action === 'standalone' => $this->products->confirmStandalone($actor, $id, $r),
            default => throw CatalogueError::notFound('page'),
        };

        return $this->done($id, 'saved');
    }

    // ---- sign-in

    private function login(Request $r): Response
    {
        if (!$r->isPost()) {
            if ($this->auth->current() !== null) {
                return Response::redirect('/admin/');
            }

            return $this->page('login.html.twig', ['email' => '']);
        }
        $result = $this->auth->attemptPassword($r->input('email'), $r->input('password'), $r->clientAddress);

        return match ($result) {
            'ok' => Response::redirect('/admin/'),
            'totp' => Response::redirect('/admin/login/totp'),
            'enroll' => Response::redirect('/admin/login/enroll'),
            default => $this->page('login.html.twig', ['email' => $r->input('email'), 'failed' => true], 401),
        };
    }

    private function totp(Request $r): Response
    {
        if (!$r->isPost()) {
            return $this->page('totp.html.twig', []);
        }

        return match ($this->auth->attemptTotp($r->input('code'))) {
            'ok' => Response::redirect('/admin/'),
            'failed' => $this->page('totp.html.twig', ['failed' => true], 401),
            default => $this->expired(),
        };
    }

    private function enroll(Request $r): Response
    {
        $secret = $this->auth->enrollmentSecret();
        $account = $this->auth->enrollmentAccount();
        if ($secret === null || $account === null) {
            return $this->expired();
        }
        if ($r->isPost()) {
            $result = $this->auth->confirmEnrollment($r->input('code'));
            if ($result === 'ok') {
                $this->flash('ok', 'ورود دومرحله‌ای فعال شد. از این پس هر ورود به کد برنامه احراز هویت نیاز دارد.');

                return Response::redirect('/admin/');
            }
            if ($result !== 'failed') {
                return $this->expired();
            }
        }

        return $this->page('enroll.html.twig', ['secret' => Totp::grouped($secret), 'uri' => Totp::uri($secret, $account, AdminAuth::ISSUER), 'failed' => $r->isPost()], $r->isPost() ? 401 : 200);
    }

    private function expired(): Response
    {
        $this->flash('error', 'مهلت ورود به پایان رسید یا ورود ناموفق بود. دوباره از ابتدا وارد شوید.');

        return Response::redirect('/admin/login');
    }

    // ---- screens

    private function dashboard(Actor $actor): Response
    {
        $counts = $this->products->counts();
        $pending = (int) \Nova\Core\Sql::scalar($this->pdo, "SELECT COUNT(*) FROM product WHERE status = 'published' AND verification = 'pending'");
        $unsourced = (int) \Nova\Core\Sql::scalar($this->pdo, 'SELECT COUNT(*) FROM spec_value v JOIN spec_key k ON k.id = v.spec_key_id WHERE k.is_core = 1 AND v.source_id IS NULL');
        $noEnglish = (int) \Nova\Core\Sql::scalar($this->pdo, "SELECT COUNT(*) FROM product WHERE status <> 'archived' AND description_fa IS NOT NULL AND description_en IS NULL");

        return $this->page('dashboard.html.twig', $this->shell($actor, 'dash') + [
            'counts' => $counts, 'pending' => $pending, 'unsourced' => $unsourced, 'no_english' => $noEnglish,
            'recent' => Activity::recent($this->pdo), 'samples' => (int) \Nova\Core\Sql::scalar($this->pdo, 'SELECT COUNT(*) FROM product WHERE is_sample = 1'),
        ]);
    }

    private function productList(Request $r, Actor $actor): Response
    {
        $q = mb_substr($r->queryString('q'), 0, 64);
        $status = $r->queryString('status');

        return $this->page('products.html.twig', $this->shell($actor, 'products') + [
            'rows' => $this->products->list($q, $status), 'q' => $q, 'status' => $status, 'counts' => $this->products->counts(),
        ]);
    }

    private function editor(int $id, Actor $actor): Response
    {
        $p = $this->products->editor($id);
        if ($p === null) {
            return $this->page('error.html.twig', ['code' => 404] + $this->shell($actor, ''), 404);
        }

        return $this->page('product.html.twig', $this->shell($actor, 'products') + ['p' => $p, 'today' => gmdate('Y-m-d')]);
    }

    private function publishPage(Actor $actor): Response
    {
        $target = $this->publish->rollbackTarget();

        return $this->page('publish.html.twig', $this->shell($actor, 'publish') + [
            'live' => $this->publish->live(), 'changes' => $this->publish->changes(), 'check' => $this->publish->check(),
            'history' => $this->publish->history($target), 'rollback_target' => $target,
        ]);
    }

    private function runPublish(Actor $actor): Response
    {
        try {
            $result = ($this->engine)()->publish(Initiator::admin($actor));
        } catch (RuntimeException $e) {
            $this->flash('error', str_contains($e->getMessage(), 'in progress') ? 'انتشار دیگری در حال اجراست؛ چند لحظه بعد دوباره تلاش کنید.' : 'انتشار انجام نشد؛ سایت زنده دست‌نخورده ماند.', $e->getMessage());

            return Response::redirect('/admin/publish');
        }
        match ($result->status) {
            'live' => $this->flash('ok', 'نسخه جدید منتشر شد و اکنون زنده است.', (string) $result->release),
            'waiting' => $this->flash('error', 'یکی از دو جایگاه انتشار هنوز در مهلت ایمنی است؛ پس از ' . AdminView::digits($result->waitSeconds) . ' ثانیه دوباره منتشر کنید.'),
            default => $this->flash('error', 'انتشار متوقف شد؛ موانع زیر باید رفع شوند. سایت زنده دست‌نخورده ماند.', implode("\n", $result->blockers)),
        };

        return Response::redirect('/admin/publish');
    }

    private function runRollback(Actor $actor): Response
    {
        try {
            $release = ($this->engine)()->rollback(Initiator::admin($actor));
            $this->flash('ok', 'سایت به نسخه قبلی برگشت.', $release);
        } catch (RuntimeException $e) {
            $this->flash('error', 'بازگشت انجام نشد؛ نسخه زنده تغییری نکرد.', $e->getMessage());
        }

        return Response::redirect('/admin/publish');
    }

    // ---- helpers

    /** @return array<string, mixed> */
    private function shell(Actor $actor, string $active): array
    {
        return ['user' => $this->auth->profile($actor), 'is_owner' => $actor->isOwner(), 'active' => $active, 'unpublished' => $this->publish->unpublishedCount(), 'site_url' => $this->siteUrl];
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context, int $status = 200): Response
    {
        $flash = $this->session->get('flash');
        $this->session->remove('flash');

        return Response::html($this->view->render($template, $context + [
            'csrf' => $this->csrf->token(),
            'flash' => \is_array($flash) ? $flash : [],
            'user' => null, 'is_owner' => false, 'active' => '', 'unpublished' => 0, 'site_url' => $this->siteUrl,
        ]), $status);
    }

    private function flash(string $type, string $text, ?string $detail = null): void
    {
        $flash = $this->session->get('flash');
        $flash = \is_array($flash) ? $flash : [];
        $flash[] = ['type' => $type, 'text' => $text, 'detail' => $detail];
        $this->session->set('flash', $flash);
    }

    private function done(int $productId, string $what): Response
    {
        $this->flash('ok', $what === 'created' ? 'پیش‌نویس محصول ساخته شد. تا وضعیت آن «منتشرشده» نشود، در سایت نمی‌آید.' : 'ذخیره شد. سایت زنده فقط با «انتشار» به‌روز می‌شود.');

        return Response::redirect("/admin/products/{$productId}");
    }

    /** Where a refused change returns to: the page it came from, never another site. */
    private function back(string $path): string
    {
        if (preg_match('#^/products/(\d+)#', $path, $m) === 1) {
            return "/admin/products/{$m[1]}";
        }
        if (preg_match('#^/admin/products/(\d+)$#', $path, $m) === 1) {
            return "/admin/products/{$m[1]}";
        }

        return match (true) {
            str_starts_with($path, '/products') => '/admin/products',
            str_starts_with($path, '/publish') => '/admin/publish',
            default => '/admin/',
        };
    }

    /** Browsers say where a request came from; a cross-site POST is refused even with a token. */
    private function sameOrigin(Request $r): bool
    {
        if ($r->fetchSite !== null && !\in_array($r->fetchSite, ['same-origin', 'none'], true)) {
            return false;
        }
        if ($r->origin !== null && $r->origin !== 'null') {
            $host = parse_url($r->origin, PHP_URL_HOST);

            return \is_string($host) && strtolower($host) === $r->hostname();
        }

        return true;
    }

    private function recover(): void
    {
        try {
            $message = ($this->engine)()->recover();
            if ($message !== null) {
                $this->flash('ok', 'یک انتشار نیمه‌تمام به‌طور خودکار سامان یافت.', $message);
            }
        } catch (Throwable) {
            // Another publish holds the lock; it recovers on its own.
        }
    }
}
