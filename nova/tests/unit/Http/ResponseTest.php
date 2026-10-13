<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Http;

use Nova\Http\Request;
use Nova\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testEveryAdminResponseCarriesTheSecurityHeaders(): void
    {
        foreach ([Response::html('x'), Response::redirect('/admin/'), new Response(404)] as $r) {
            self::assertSame(Response::CSP, $r->headers['Content-Security-Policy']);
            self::assertStringNotContainsString('unsafe-inline', $r->headers['Content-Security-Policy']);
            self::assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
            self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
            self::assertSame('DENY', $r->headers['X-Frame-Options']);
            self::assertSame('no-store', $r->headers['Cache-Control']);
            self::assertSame('noindex, nofollow', $r->headers['X-Robots-Tag']);
        }
    }

    public function testRedirectsStayOnThisSite(): void
    {
        self::assertSame('/admin/products/3', Response::redirect('/admin/products/3')->headers['Location']);
        foreach (['https://evil.test/', '//evil.test/x', 'javascript:alert(1)', ''] as $target) {
            self::assertSame('/admin/', Response::redirect($target)->headers['Location'], $target);
        }
    }

    public function testRequestHostWithoutPortAndTypedInput(): void
    {
        $r = new Request('POST', '/admin/x', 'FA.nova.localhost:8080', post: ['a' => 'b', 'arr' => ['c']]);
        self::assertSame('fa.nova.localhost:8080', strtolower($r->host));
        self::assertSame('FA.nova.localhost', $r->hostname());
        self::assertSame('b', $r->input('a'));
        self::assertSame('', $r->input('arr'), 'an array where a string is expected reads as empty');
        self::assertSame('', $r->input('missing'));
    }
}
