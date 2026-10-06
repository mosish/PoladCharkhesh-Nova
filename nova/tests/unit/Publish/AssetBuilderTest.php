<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Publish;

use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Publish\AssetBuilder;
use PHPUnit\Framework\TestCase;

final class AssetBuilderTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/nova-assets-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->out);
    }

    public function testBuildsHashedAssetsAndRewritesModuleImports(): void
    {
        $manifest = AssetBuilder::build(Paths::fromHere()->root . '/assets-src', $this->out);

        self::assertMatchesRegularExpression('#^/assets/nova\.[0-9a-f]{12}\.css$#', $manifest['nova.css']);
        $css = (string) file_get_contents($this->out . '/' . basename($manifest['nova.css']));
        self::assertStringContainsString('/* css/nova.css */', $css, 'the frozen design system comes first');
        self::assertStringContainsString('/* css/nova-production.css */', $css);

        $nova = (string) file_get_contents($this->out . '/' . basename($manifest['nova.js']));
        self::assertStringContainsString("from './" . basename($manifest['designation.js']) . "'", $nova);
        self::assertStringContainsString("'./" . basename($manifest['ws.js']) . "'", $nova);
        self::assertDoesNotMatchRegularExpression("#['\"`]\\./(designation|ws)\\.js#", $nova, 'no unhashed specifier left');

        self::assertArrayHasKey('fonts/vazirmatn-Regular-fa.woff2', $manifest);
        self::assertSame($manifest, AssetBuilder::manifest($this->out));
        self::assertSame($manifest, AssetBuilder::build(Paths::fromHere()->root . '/assets-src', $this->out), 'deterministic');
    }
}
