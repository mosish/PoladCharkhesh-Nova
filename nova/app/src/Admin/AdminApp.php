<?php

declare(strict_types=1);

namespace Nova\Admin;

use Nova\Catalogue\CatalogueError;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Inquiry\InquiryNotifier;
use Nova\Inquiry\InquiryRecipient;
use Nova\Inquiry\InquiryRetention;
use Nova\Media\DocumentService;
use Nova\Media\MediaError;
use Nova\Media\MediaLimits;
use Nova\Media\MediaService;
use Nova\Media\MediaStorage;
use Nova\Publish\Clock;
use Nova\Publish\Initiator;
use Nova\Publish\PublishEngine;
use Nova\Publish\RecoveryFailed;
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
    private readonly InquiryAdmin $inquiries;
    private readonly MediaService $media;
    private readonly DocumentService $documents;

    /**
     * @param \Closure(): PublishEngine $engine
     * @param \Closure(int, string): ?string $preview renders a draft Product page (id, language) or null
     * @param (\Closure(): InquiryNotifier)|null $notifier sends inquiry notifications (Retry); null = not configured
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
        private readonly ?\Closure $notifier = null,
        ?MediaStorage $mediaStorage = null,
        MediaLimits $mediaLimits = new MediaLimits(),
    ) {
        $this->auth = new AdminAuth($pdo, $session, $clock);
        $this->csrf = new Csrf($session);
        $this->products = new ProductAdmin($pdo);
        $this->inquiries = new InquiryAdmin($pdo);
        $storage = $mediaStorage ?? new MediaStorage(\dirname(__DIR__, 3) . '/public_html');
        $audit = new \Nova\Audit\AuditLog($pdo);
        $this->media = new MediaService($pdo, $storage, $mediaLimits, $clock, $audit);
        $this->documents = new DocumentService($pdo, $storage, $mediaLimits, $clock, $audit);
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
        } catch (MediaError $e) {
            if ($e->reason === 'not_found' && !$r->isPost()) {
                return $this->page('error.html.twig', ['code' => 404] + $this->shell($actor, ''), 404);
            }
            $this->flash('error', Messages::media($e->reason), $e->getMessage());

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
        if (str_starts_with($path, '/inquiries')) {
            return $this->inquiryRoute($r, $path, $actor);
        }
        if (str_starts_with($path, '/media')) {
            return $this->mediaRoute($r, $path, $actor);
        }
        if (str_starts_with($path, '/documents')) {
            return $this->documentRoute($r, $path, $actor);
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
            'inq' => $this->inquiries->counts(), 'recent_inq' => $this->inquiries->list('all', '', 4), 'demand' => $this->inquiries->notInCatalogue(),
            'recipient' => (new InquiryRecipient($this->pdo))->get(),
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

    // ---- media library and documents (A4; 05 §12, §26)

    private function mediaRoute(Request $r, string $path, Actor $actor): ?Response
    {
        $get = !$r->isPost();
        if ($path === '/media') {
            if ($get) {
                $trash = $r->queryString('trash') === '1';
                $kind = $r->queryString('kind');
                $q = mb_substr(trim($r->queryString('q')), 0, 64);

                return $this->page('media.html.twig', $this->shell($actor, 'media') + [
                    'rows' => $this->media->list($kind, $q, $trash), 'trash' => $trash, 'kind' => $kind, 'q' => $q,
                ]);
            }
            $file = $r->file('file');
            if ($file === null || !$file->ok()) {
                throw new MediaError('invalid', 'No file was received (PHP upload error ' . ($file->error ?? UPLOAD_ERR_NO_FILE) . ').');
            }
            $result = $this->media->upload($actor, $file->name, $file->bytes);
            $this->flash('ok', $result['existing'] ? 'این فایل از پیش در کتابخانه بود؛ همان مورد باز شد.' : 'فایل بارگذاری شد. متن جایگزین فارسی و انگلیسی را وارد کنید.', $result['removed'] === [] ? null : 'Removed from the SVG: ' . implode('; ', $result['removed']));

            return Response::redirect("/admin/media/{$result['id']}");
        }
        if (preg_match('#^/media/(\d+)(?:/(update|replace|trash|restore))?$#', $path, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];
        $action = $m[2] ?? '';
        if ($get) {
            $item = $action === '' ? $this->media->find($id) : null;

            return $item === null ? null : $this->page('media_detail.html.twig', $this->shell($actor, 'media') + ['m' => $item]);
        }
        switch ($action) {
            case 'update':
                $this->media->updateDescription($actor, $id, (int) $r->input('version'), $r->input('alt_fa'), $r->input('alt_en'), $r->input('role'));
                $this->flash('ok', 'مشخصات ذخیره شد.');
                break;
            case 'replace':
                $file = $r->file('file');
                if ($file === null || !$file->ok()) {
                    throw new MediaError('invalid', 'No file was received.');
                }
                $removed = $this->media->replace($actor, $id, $file->name, $file->bytes);
                $this->flash('ok', 'فایل جایگزین شد؛ شناسه، متن جایگزین و ارجاع‌ها حفظ شدند. سایت زنده با «انتشار» به‌روز می‌شود.', $removed === [] ? null : 'Removed from the SVG: ' . implode('; ', $removed));
                break;
            case 'trash':
                $this->media->trash($actor, $id);
                $this->flash('ok', 'به سطل بازیافت رفت و ۳۰ روز قابل بازگردانی است.');

                return Response::redirect('/admin/media');
            case 'restore':
                $this->media->restore($actor, $id);
                $this->flash('ok', 'بازگردانده شد.');
                break;
            default:
                return null;
        }

        return Response::redirect("/admin/media/{$id}");
    }

    private function documentRoute(Request $r, string $path, Actor $actor): ?Response
    {
        $get = !$r->isPost();
        if ($path === '/documents') {
            if ($get) {
                $trash = $r->queryString('trash') === '1';
                $q = mb_substr(trim($r->queryString('q')), 0, 64);

                return $this->page('documents.html.twig', $this->shell($actor, 'docs') + ['rows' => $this->documents->list($trash, $q), 'trash' => $trash, 'q' => $q]);
            }
            $file = $r->file('file');
            if ($file === null || !$file->ok()) {
                throw new MediaError('invalid', 'No file was received (PHP upload error ' . ($file->error ?? UPLOAD_ERR_NO_FILE) . ').');
            }
            $result = $this->documents->upload($actor, $file->name, $file->bytes, $r->input('title_fa'), $r->input('title_en'));
            $this->flash('ok', $result['existing'] ? 'این سند از پیش در کتابخانه بود؛ همان مورد باز شد.' : 'سند بارگذاری شد. آن را به یک کد وصل کنید تا در سایت نمایش داده شود.');

            return Response::redirect("/admin/documents/{$result['id']}");
        }
        if (preg_match('#^/documents/(\d+)(?:/(update|link|unlink|trash|restore))?$#', $path, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];
        $action = $m[2] ?? '';
        if ($get) {
            $doc = $action === '' ? $this->documents->find($id) : null;

            return $doc === null ? null : $this->page('document_detail.html.twig', $this->shell($actor, 'docs') + ['d' => $doc]);
        }
        switch ($action) {
            case 'update':
                $this->documents->setTitles($actor, $id, (int) $r->input('version'), $r->input('title_fa'), $r->input('title_en'));
                $this->flash('ok', 'عنوان‌ها ذخیره شد.');
                break;
            case 'link':
                $this->documents->link($actor, $id, $r->input('designation'), $r->input('language'), 'document');
                $this->flash('ok', 'سند به این کد وصل شد. سایت زنده با «انتشار» به‌روز می‌شود.');
                break;
            case 'unlink':
                $this->documents->unlink($actor, (int) $r->input('link'));
                $this->flash('ok', 'اتصال سند برداشته شد.');
                break;
            case 'trash':
                $this->documents->trash($actor, $id);
                $this->flash('ok', 'سند به سطل بازیافت رفت و ۳۰ روز قابل بازگردانی است.');

                return Response::redirect('/admin/documents');
            case 'restore':
                $this->documents->restore($actor, $id);
                $this->flash('ok', 'سند بازگردانده شد.');
                break;
            default:
                return null;
        }

        return Response::redirect("/admin/documents/{$id}");
    }

    // ---- inquiries (A6; 05 §13)

    private function inquiryRoute(Request $r, string $path, Actor $actor): ?Response
    {
        $get = !$r->isPost();
        if ($path === '/inquiries') {
            return $get ? $this->inquiryPage($r, $actor, null) : null;
        }
        if ($path === '/inquiries/settings') {
            if ($get) {
                return $this->page('inquiry_settings.html.twig', $this->shell($actor, 'inq') + ['recipient' => (new InquiryRecipient($this->pdo))->get()]);
            }
            (new InquiryRecipient($this->pdo))->set($actor, $r->input('notify_to'));
            $this->flash('ok', 'نشانی دریافت اعلان استعلام ذخیره شد.');

            return Response::redirect('/admin/inquiries/settings');
        }
        if ($path === '/inquiries/retry' && !$get) {
            $result = $this->notifier()->retryAll();
            $this->flashRetry($result['sent'], $result['failed']);

            return Response::redirect('/admin/inquiries');
        }
        if (preg_match('#^/inquiries/(\d+)(?:/(status|note|retry|delete))?$#', $path, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];
        $action = $m[2] ?? '';
        if ($get) {
            return $action === '' ? $this->inquiryPage($r, $actor, $id) : null;
        }
        switch ($action) {
            case 'status':
                $this->inquiries->setStatus($actor, $id, $r->input('status'));
                $this->flash('ok', 'وضعیت استعلام تغییر کرد.');
                break;
            case 'note':
                $this->inquiries->saveNote($actor, $id, $r->input('note'));
                $this->flash('ok', 'یادداشت داخلی ذخیره شد.');
                break;
            case 'retry':
                $outcome = $this->notifier()->deliver($id);
                $this->flashRetry($outcome === 'sent' ? 1 : 0, $outcome === 'failed' ? 1 : 0);
                break;
            case 'delete':
                if (!$actor->isOwner()) {
                    return $this->page('error.html.twig', ['code' => 403, 'reason' => 'role'] + $this->shell($actor, ''), 403);
                }
                if ($r->input('confirm') !== '1') {
                    $this->flash('error', 'برای حذف، تأیید را علامت بزنید. حذف برگشت‌پذیر نیست.');
                    break;
                }
                (new InquiryRetention($this->pdo))->delete($actor, $id);
                $this->flash('ok', 'استعلام و مشخصات آن حذف شد.');

                return Response::redirect('/admin/inquiries');
            default:
                return null;
        }

        return Response::redirect("/admin/inquiries/{$id}");
    }

    private function inquiryPage(Request $r, Actor $actor, ?int $id): Response
    {
        $status = $r->queryString('status');
        $status = \in_array($status, [...InquiryAdmin::STATUSES, 'all'], true) ? $status : ($id === null ? 'new' : 'all');
        $q = mb_substr(trim($r->queryString('q')), 0, 64);
        $selected = $id === null ? null : $this->inquiries->detail($id);
        if ($id !== null && $selected === null) {
            return $this->page('error.html.twig', ['code' => 404] + $this->shell($actor, 'inq'), 404);
        }

        return $this->page('inquiries.html.twig', $this->shell($actor, 'inq') + [
            'rows' => $this->inquiries->list($status, $q), 'sel' => $selected, 'status' => $status, 'q' => $q,
            'counts' => $this->inquiries->counts(), 'recipient' => (new InquiryRecipient($this->pdo))->get(), 'can_retry' => $this->notifier !== null,
        ]);
    }

    private function notifier(): InquiryNotifier
    {
        if ($this->notifier === null) {
            throw new RuntimeException('Inquiry notifications are not configured for this Admin.');
        }

        return ($this->notifier)();
    }

    private function flashRetry(int $sent, int $failed): void
    {
        if ($failed > 0) {
            $this->flash('error', "ارسال اعلان برای {$this->fa($failed)} استعلام دوباره ناموفق بود. علت کنار هر استعلام آمده است؛ استعلام‌ها سالم و ذخیره‌اند.");
        } elseif ($sent > 0) {
            $this->flash('ok', "اعلان {$this->fa($sent)} استعلام ارسال شد.");
        } else {
            $this->flash('ok', 'اعلانی در انتظار ارسال نبود.');
        }
    }

    private function fa(int $n): string
    {
        return AdminView::digits($n);
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
        $engine = ($this->engine)();
        $before = self::liveOf($engine);
        try {
            $result = $engine->publish(Initiator::admin($actor));
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'in progress')) {
                $this->flash('error', 'انتشار دیگری در حال اجراست؛ چند لحظه بعد دوباره تلاش کنید.', $e->getMessage());
            } else {
                $this->flashAfterError('انتشار', $before, $engine, $e);
            }

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
        $engine = ($this->engine)();
        $before = self::liveOf($engine);
        try {
            $release = $engine->rollback(Initiator::admin($actor));
            $this->flash('ok', 'سایت به نسخه قبلی برگشت.', $release);
        } catch (RuntimeException $e) {
            $this->flashAfterError('بازگشت', $before, $engine, $e);
        }

        return Response::redirect('/admin/publish');
    }

    /**
     * After a failed publish or rollback, says only what is verified. The
     * engine normally recovers on its error path (a switch that happened is
     * finished, one that did not is undone). The state counts as verified only
     * when that recovery succeeded, nothing is left waiting in the journal,
     * and the live release can be read, before and after. Otherwise the
     * message says the current state could not be verified, and claims
     * neither "unchanged" nor a completed recovery.
     *
     * @param string|false|null $before the live release before; null = verified that none was live; false = unknown
     */
    private function flashAfterError(string $operation, string|false|null $before, PublishEngine $engine, RuntimeException $e): void
    {
        $after = self::liveOf($engine);
        $pending = true;
        try {
            $pending = $engine->pendingRecovery();
        } catch (Throwable) {
        }
        if ($e instanceof RecoveryFailed || $pending || $after === false || $before === false) {
            $known = $after === false ? '' : ' آخرین وضعیت خوانده‌شده: ' . ($after === null ? 'نسخه زنده‌ای نیست' : "نسخه {$after}") . '.';
            $this->flash('error', "{$operation} با خطا روبه‌رو شد و وضعیت فعلی سایت قابل تأیید نیست: بازیابی خودکار کامل نشد یا نسخه زنده خوانده نشد.{$known} پیش از هر کار دیگر، وضعیت را در خط فرمان با publish:status و publish:recover بررسی کنید.", $e->getMessage());

            return;
        }
        $now = $after === null ? 'هنوز نسخه‌ای زنده نیست' : "نسخه زنده: {$after}";
        $this->flash('error', match (true) {
            $after !== $before && $after !== null => "{$operation} با خطا روبه‌رو شد، اما جابه‌جایی انجام شده بود و بازیابی خودکار آن را کامل کرد. نسخه {$after} اکنون زنده است.",
            $after !== $before => "{$operation} با خطا روبه‌رو شد و نسخه زنده‌ای نمانده است.",
            $operation === 'انتشار' => "انتشار انجام نشد؛ سایت زنده دست‌نخورده ماند ({$now}).",
            default => "بازگشت انجام نشد؛ نسخه زنده تغییری نکرد ({$now}).",
        }, $e->getMessage());
    }

    /** The live release read from the switch; null when it verifiably has none; false when it cannot be read. */
    private static function liveOf(PublishEngine $engine): string|false|null
    {
        try {
            return $engine->live();
        } catch (Throwable) {
            return false;
        }
    }

    // ---- helpers

    /** @return array<string, mixed> */
    private function shell(Actor $actor, string $active): array
    {
        return ['user' => $this->auth->profile($actor), 'is_owner' => $actor->isOwner(), 'active' => $active, 'unpublished' => $this->publish->unpublishedCount(), 'site_url' => $this->siteUrl,
            'inq_new' => \Nova\Core\Sql::int($this->pdo, "SELECT COUNT(*) FROM inquiry WHERE status = 'new'"),
            'inq_failed' => \Nova\Core\Sql::int($this->pdo, "SELECT COUNT(*) FROM inquiry WHERE notify_status = 'failed'")];
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context, int $status = 200): Response
    {
        $flash = $this->session->get('flash');
        $this->session->remove('flash');

        return Response::html($this->view->render($template, $context + [
            'csrf' => $this->csrf->token(),
            'flash' => \is_array($flash) ? $flash : [],
            'user' => null, 'is_owner' => false, 'active' => '', 'unpublished' => 0, 'site_url' => $this->siteUrl, 'inq_new' => 0, 'inq_failed' => 0,
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
            preg_match('#^/inquiries/(\d+)#', $path, $m) === 1 => "/admin/inquiries/{$m[1]}",
            preg_match('#^/media/(\d+)#', $path, $m) === 1 => "/admin/media/{$m[1]}",
            preg_match('#^/documents/(\d+)#', $path, $m) === 1 => "/admin/documents/{$m[1]}",
            str_starts_with($path, '/media') => '/admin/media',
            str_starts_with($path, '/documents') => '/admin/documents',
            str_starts_with($path, '/inquiries') => '/admin/inquiries',
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
