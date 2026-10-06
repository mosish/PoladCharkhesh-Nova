<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Content;

use Nova\Content\RichText\BrokenReferences;
use Nova\Content\RichText\InlineSyntax;
use Nova\Content\RichText\Profile;
use Nova\Content\RichText\RenderContext;
use Nova\Content\RichText\Renderer;
use Nova\Content\RichText\RichText;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function context(): RenderContext
    {
        return new class implements RenderContext {
            public function language(): string
            {
                return 'fa';
            }

            public function headingBase(): int
            {
                return 3;
            }

            public function productUrl(string $slug): ?string
            {
                return $slug === '6205' ? '/p/6205/' : null;
            }

            public function sectionUrl(string $id): ?string
            {
                return $id === 'about' ? '/#about' : null;
            }

            public function document(int $id): ?array
            {
                return $id === 5 ? ['url' => '/media/docs/x.pdf', 'label' => 'PDF · 1.2 MB'] : null;
            }

            public function media(int $id): ?array
            {
                return $id === 3 ? ['src' => '/media/2026/abc.webp', 'width' => 640, 'height' => 480] : null;
            }
        };
    }

    private function render(string $syntax, Profile $profile = Profile::Basic): string
    {
        return Renderer::render(RichText::paragraph($syntax), $profile, $this->context());
    }

    public function testMarksCodesAndLinks(): void
    {
        self::assertSame(
            "<p>a <strong>b</strong> <em>c</em> <strong><em>d</em></strong> <bdi class=\"mono\" dir=\"ltr\">6205-2RS</bdi></p>\n",
            $this->render('a **b** *c* ***d*** `6205-2RS`'),
        );
        self::assertSame(
            "<p><a href=\"https://example.test/a?b=1&amp;c=2\" rel=\"noopener noreferrer\">ext</a> <a href=\"/p/6205/\">6205</a> <a href=\"/#about\">about</a></p>\n",
            $this->render('[ext](https://example.test/a?b=1&c=2) [6205](product:6205) [about](section:about)'),
        );
    }

    public function testEverythingTypedIsEscaped(): void
    {
        $attack = '<img src=x onerror=alert(1)> "\'&';
        $html = $this->render($attack . ' `' . $attack . '`');
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; &quot;&apos;&amp;', $html);

        $doc = RichText::document([
            ['type' => 'figure', 'media' => 3, 'alt' => '"><script>x</script>', 'decorative' => false, 'caption' => InlineSyntax::parse('<b>c</b>')],
            ['type' => 'table', 'caption' => InlineSyntax::parse('<i>'), 'header' => [InlineSyntax::parse('<th>')], 'rows' => [[InlineSyntax::parse('<td onclick=x>')]]],
        ]);
        $html = Renderer::render($doc, Profile::Article, $this->context());
        self::assertDoesNotMatchRegularExpression('/<(?:script|b|i|td onclick)[\s>]/', $html);
        self::assertStringContainsString('alt="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $html);
    }

    public function testOnlyAllowListedElementsAreEmitted(): void
    {
        $doc = RichText::document([
            ['type' => 'heading', 'level' => 1, 'content' => InlineSyntax::parse('H')],
            ['type' => 'heading', 'level' => 2, 'content' => InlineSyntax::parse('h')],
            ['type' => 'list', 'ordered' => true, 'items' => [['content' => InlineSyntax::parse('i'), 'items' => [['content' => InlineSyntax::parse('j')]]]]],
            ['type' => 'table', 'caption' => InlineSyntax::parse('Cap'), 'header' => [InlineSyntax::parse('A')], 'rows' => [[InlineSyntax::parse('1')]]],
            ['type' => 'figure', 'media' => 3, 'alt' => '', 'decorative' => true],
            ['type' => 'document', 'document' => 5, 'label' => InlineSyntax::parse('Datasheet')],
        ]);
        $html = Renderer::render($doc, Profile::Article, $this->context());
        preg_match_all('/<([a-z0-9]+)/', $html, $m);
        self::assertSame([], array_values(array_diff(array_unique($m[1]), ['h3', 'h4', 'ol', 'li', 'div', 'table', 'caption', 'thead', 'tr', 'th', 'tbody', 'td', 'figure', 'img', 'p', 'a', 'span'])));
        self::assertStringContainsString('<h3>H</h3>', $html, 'level 1 sits under the section heading');
        self::assertStringContainsString('<th scope="col">A</th>', $html);
        self::assertStringContainsString('alt=""', $html, 'decorative figures are silent');
        self::assertStringContainsString('<a href="/media/docs/x.pdf">Datasheet</a> <span class="rc-doc__meta">PDF · 1.2 MB</span>', $html);
    }

    public function testBrokenInternalReferencesBlock(): void
    {
        $doc = RichText::document([
            ['type' => 'paragraph', 'content' => InlineSyntax::parse('[x](product:9999) [y](section:nowhere) [z](document:77)')],
            ['type' => 'figure', 'media' => 8, 'alt' => 'a', 'decorative' => false],
        ]);
        try {
            Renderer::render($doc, Profile::Article, $this->context());
            self::fail('broken references were rendered');
        } catch (BrokenReferences $e) {
            self::assertSame(['product:9999', 'section:nowhere', 'document:77', 'media:8'], $e->references);
        }
    }

    public function testInvalidDocumentsAreNeverRendered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Renderer::render(RichText::document([['type' => 'heading', 'level' => 1, 'content' => InlineSyntax::parse('x')]]), Profile::Basic, $this->context());
    }

    public function testPlainTextAndReferences(): void
    {
        $doc = RichText::document([
            ['type' => 'paragraph', 'content' => InlineSyntax::parse("**Deep**  groove\n[ball](product:6205) `6205`")],
            ['type' => 'figure', 'media' => 3, 'alt' => 'x', 'decorative' => false],
            ['type' => 'document', 'document' => 5, 'label' => InlineSyntax::parse('d')],
        ]);
        self::assertSame('Deep groove ball 6205 d', Renderer::plainText($doc));
        self::assertSame(['product:6205', 'media:3', 'document:5'], Renderer::references($doc));
    }
}
