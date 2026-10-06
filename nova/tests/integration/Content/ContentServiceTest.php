<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Content;

use Nova\Catalogue\CatalogueError;
use Nova\Content\ContentService;
use Nova\Content\RichText\InlineSyntax;
use Nova\Content\RichText\RichText;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use PHPUnit\Framework\TestCase;

final class ContentServiceTest extends TestCase
{
    public function testProductDescriptionIsValidatedVersionedAndAudited(): void
    {
        $f = new CatalogueFixture();
        $content = new ContentService($f->pdo, $f->audit);
        $p = $f->products->createProduct($f->editor, $f->family, 'QZ 100');
        $doc = RichText::paragraph('Deep groove, see `QZ 100`.');

        $content->setProductDescription($f->editor, $p, 1, 'fa', $doc);
        self::assertSame($doc, $content->productDescription($p, 'fa'));
        self::assertNull($content->productDescription($p, 'en'));
        self::assertContains('content.product_description', $f->auditActions());

        foreach ([
            'invalid' => fn() => $content->setProductDescription($f->editor, $p, 2, 'fa', RichText::document([['type' => 'heading', 'level' => 1, 'content' => InlineSyntax::parse('x')]])),
            'stale' => fn() => $content->setProductDescription($f->editor, $p, 1, 'fa', $doc),
        ] as $reason => $action) {
            try {
                $action();
                self::fail("expected {$reason}");
            } catch (CatalogueError $e) {
                self::assertSame($reason, $e->reason);
            }
        }

        $content->setProductDescription($f->editor, $p, 2, 'fa', null);
        self::assertNull($content->productDescription($p, 'fa'));
    }
}
