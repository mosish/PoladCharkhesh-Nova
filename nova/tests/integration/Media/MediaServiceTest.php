<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Media;

use Nova\Core\Files;
use Nova\Core\Sql;
use Nova\Media\DocumentService;
use Nova\Media\ImageProcessor;
use Nova\Media\MediaError;
use Nova\Media\MediaLimits;
use Nova\Media\MediaService;
use Nova\Media\MediaStorage;
use Nova\Media\SvgSanitizer;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use Nova\Tests\Integration\Publish\TestClock;
use PHPUnit\Framework\TestCase;

/** The media core on a disposable database and a temporary media folder. Synthetic files only. */
final class MediaServiceTest extends TestCase
{
    private string $tmp;
    private CatalogueFixture $f;
    private TestClock $clock;
    private MediaStorage $storage;
    private MediaService $media;
    private DocumentService $documents;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-media-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
        $this->f = new CatalogueFixture();
        $this->clock = new TestClock(1_800_000_000);
        $this->storage = new MediaStorage($this->tmp);
        $limits = new MediaLimits(maxImageBytes: 5_000_000, maxPixels: 4_000_000, maxPdfBytes: 1_000_000, maxSvgBytes: 20_000);
        $this->media = new MediaService($this->f->pdo, $this->storage, $limits, $this->clock, $this->f->audit, static fn(): array => []);
        $this->documents = new DocumentService($this->f->pdo, $this->storage, $limits, $this->clock, $this->f->audit);
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->tmp);
    }

    private static function i(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : -1;
    }

    private static function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** A JPEG with an EXIF block carrying an orientation and a GPS-looking comment. */
    private static function jpegWithExif(int $w, int $h, int $orientation): string
    {
        $im = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, 20, 120, 220));
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        $tiff = "II*\x00\x08\x00\x00\x00" . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0) . 'GPS:35.6892N,51.3890E';
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + \strlen($tiff)) . "Exif\x00\x00" . $tiff;

        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    // ---- raster

    public function testARasterImageIsReEncodedHashedAndGivenResponsiveCopies(): void
    {
        $r = $this->media->upload($this->f->editor, 'Bearing Photo.PNG', self::png(2000, 1000));
        self::assertFalse($r['existing']);
        $row = $this->media->find($r['id']);
        self::assertNotNull($row);
        self::assertSame(['raster', 'png', 'image/png', 2000, 1000], [$row['kind'], $row['ext'], $row['mime'], self::i($row['width']), self::i($row['height'])]);
        self::assertSame('Bearing Photo.PNG', $row['original_name']);
        $file = $this->storage->path(self::i($row['year']), self::s($row['sha256']), 'png');
        self::assertFileExists($file);
        self::assertSame($row['sha256'], hash_file('sha256', $file), 'named by the hash of what is stored');
        self::assertSame('/media/2027/' . self::s($row['sha256']) . '.png', $row['url']);
        self::assertSame('480,960,1600', $row['derivatives']);
        foreach ([480, 960, 1600] as $w) {
            $info = getimagesize($this->storage->path(self::i($row['year']), self::s($row['sha256']), 'webp', $w));
            self::assertIsArray($info);
            self::assertSame([$w, 'image/webp'], [$info[0], $info['mime']]);
        }
        $small = $this->media->upload($this->f->editor, 'small.png', self::png(300, 300));
        self::assertNull(Sql::scalar($this->f->pdo, 'SELECT derivatives FROM media WHERE id = ?', [$small['id']]), 'no copy is wider than the original');
    }

    public function testExifIsRemovedAndOrientationAppliedToThePixels(): void
    {
        $jpeg = self::jpegWithExif(40, 20, 6);
        self::assertStringContainsString('GPS:35.6892N', $jpeg);
        $r = $this->media->upload($this->f->editor, 'phone.jpg', $jpeg);
        $row = $this->media->find($r['id']);
        self::assertNotNull($row);
        self::assertSame([20, 40], [self::i($row['width']), self::i($row['height'])], 'rotated to upright');
        $stored = (string) file_get_contents($this->storage->path(self::i($row['year']), self::s($row['sha256']), 'jpg'));
        self::assertStringNotContainsString('GPS:', $stored);
        self::assertStringNotContainsString('Exif', $stored);
    }

    public function testTheFileContentDecidesNotTheNameOrTrailingPayloads(): void
    {
        $php = "<?php echo 'x';";
        foreach (['shell.jpg' => $php, 'a.png' => 'GIF89a' . str_repeat("\x00", 50), 'empty.png' => '', 'text.webp' => 'hello'] as $name => $bytes) {
            try {
                $this->media->upload($this->f->editor, $name, $bytes);
                self::fail("accepted {$name}");
            } catch (MediaError $e) {
                self::assertContains($e->reason, ['unsupported', 'too_large', 'invalid'], $name);
            }
        }
        // A real PNG with a PHP payload appended: the payload does not survive re-encoding.
        $r = $this->media->upload($this->f->editor, 'poly.png', self::png(50, 50) . $php);
        $row = $this->media->find($r['id']);
        self::assertNotNull($row);
        self::assertStringNotContainsString('<?php', (string) file_get_contents($this->storage->path(self::i($row['year']), self::s($row['sha256']), 'png')));
        self::assertSame(0, Sql::int($this->f->pdo, "SELECT COUNT(*) FROM media WHERE original_name LIKE 'shell%'"));
    }

    public function testDecompressionBombsAndOversizeFilesAreRefused(): void
    {
        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('more pixels');
        $this->media->upload($this->f->editor, 'huge.png', self::png(3000, 3000));
    }

    public function testTheSameBytesAreStoredOnceAndATrashedItemComesBack(): void
    {
        $a = $this->media->upload($this->f->editor, 'one.png', self::png(60, 60));
        $b = $this->media->upload($this->f->editor, 'copy.png', self::png(60, 60));
        self::assertSame([$a['id'], true], [$b['id'], $b['existing']]);
        self::assertSame(1, Sql::int($this->f->pdo, 'SELECT COUNT(*) FROM media'));
        $this->media->trash($this->f->editor, $a['id']);
        self::assertSame([], $this->media->list('', '', false));
        $c = $this->media->upload($this->f->editor, 'again.png', self::png(60, 60));
        self::assertSame([$a['id'], true], [$c['id'], $c['existing']]);
        self::assertCount(1, $this->media->list('', '', false), 're-uploading restores it');
    }

    // ---- alt text, replace, references, trash

    public function testAltTextRoleAndStaleEdits(): void
    {
        $id = $this->media->upload($this->f->editor, 'a.png', self::png(60, 60))['id'];
        $v = $this->f->version('media', $id);
        $this->media->updateDescription($this->f->editor, $id, $v, 'نمای مقطع', 'Section view', 'informative');
        $row = $this->media->find($id);
        self::assertSame(['نمای مقطع', 'Section view'], [$row['alt_fa'] ?? null, $row['alt_en'] ?? null]);
        foreach ([[$v, 'informative'], [$v + 1, 'sideways']] as [$version, $role]) {
            try {
                $this->media->updateDescription($this->f->editor, $id, $version, 'x', 'y', $role);
                self::fail('accepted');
            } catch (MediaError $e) {
                self::assertContains($e->reason, ['stale', 'invalid']);
            }
        }
        $this->expectException(MediaError::class);
        $this->media->updateDescription($this->f->editor, $id, $v + 1, str_repeat('x', 301), null, 'informative');
    }

    public function testAnInformativeImageWithoutBothAltTextsBlocksPublishingWhileUsed(): void
    {
        $id = $this->media->upload($this->f->editor, 'diagram.png', self::png(60, 60))['id'];
        $product = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        Sql::run($this->f->pdo, "UPDATE product SET status = 'published' WHERE id = ?", [$product]);
        self::assertSame([], $this->media->missingAlt(), 'unused items do not block');
        $doc = json_encode(['format' => 'nova-rich-text/1', 'blocks' => [['type' => 'figure', 'media' => $id, 'alt' => 'x', 'decorative' => false]]], JSON_THROW_ON_ERROR);
        Sql::run($this->f->pdo, 'UPDATE product SET description_fa = ? WHERE id = ?', [$doc, $product]);
        self::assertCount(1, $this->media->missingAlt());
        self::assertStringContainsString('ALT-INF', $this->media->missingAlt()[0]);
        $this->media->updateDescription($this->f->editor, $id, 1, 'الف', 'a', 'informative');
        self::assertSame([], $this->media->missingAlt());
        $this->media->updateDescription($this->f->editor, $id, 2, null, null, 'decorative');
        self::assertSame([], $this->media->missingAlt(), 'a decorative image needs none');
    }

    public function testDeletingIsBlockedWhileReferencedAndTheTrashKeeps30Days(): void
    {
        $id = $this->media->upload($this->f->editor, 'used.png', self::png(60, 60))['id'];
        $product = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        Sql::run($this->f->pdo, 'UPDATE product SET description_en = ? WHERE id = ?', [json_encode(['format' => 'nova-rich-text/1', 'blocks' => [['type' => 'figure', 'media' => $id, 'alt' => 'x', 'decorative' => false]]]), $product]);
        try {
            $this->media->trash($this->f->editor, $id);
            self::fail('trashed while used');
        } catch (MediaError $e) {
            self::assertSame(['referenced', ['QZ 1']], [$e->reason, $e->detail]);
        }
        Sql::run($this->f->pdo, 'UPDATE product SET description_en = NULL WHERE id = ?', [$product]);
        $this->media->trash($this->f->editor, $id);
        $row = $this->media->find($id);
        self::assertNotNull($row);
        $file = $this->storage->path(self::i($row['year']), self::s($row['sha256']), 'png');
        self::assertFileExists($file, 'a trashed file stays on disk: releases may still link to it');

        Sql::run($this->f->pdo, 'UPDATE media SET trashed_at = UTC_TIMESTAMP() - INTERVAL 29 DAY WHERE id = ?', [$id]);
        self::assertSame(['items' => 0, 'files' => 0], $this->media->purgeTrash());
        Sql::run($this->f->pdo, 'UPDATE media SET trashed_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = ?', [$id]);
        $this->clock->time += 31 * 86_400;
        touch($file, $this->clock->time - 40 * 86_400);
        self::assertSame(['items' => 1, 'files' => 1], $this->media->purgeTrash());
        self::assertFileDoesNotExist($file);
        self::assertNull($this->media->find($id));
    }

    public function testReplacingAFileKeepsTheIdAndAltTextAndTheOldFileUntilTheSweep(): void
    {
        $id = $this->media->upload($this->f->editor, 'v1.png', self::png(60, 60))['id'];
        $this->media->updateDescription($this->f->editor, $id, 1, 'الف', 'a', 'informative');
        $old = $this->media->find($id);
        self::assertNotNull($old);
        $oldFile = $this->storage->path(self::i($old['year']), self::s($old['sha256']), 'png');

        $this->media->replace($this->f->editor, $id, 'v2.png', self::png(80, 40));
        $new = $this->media->find($id);
        self::assertNotNull($new);
        self::assertSame([$id, 'الف', 80, 40, 'v2.png'], [self::i($new['id']), $new['alt_fa'], self::i($new['width']), self::i($new['height']), $new['original_name']]);
        self::assertNotSame($old['sha256'], $new['sha256']);
        self::assertFileExists($oldFile, 'a live release may still link to the superseded file');

        $this->clock->time += 29 * 86_400;
        self::assertSame(0, $this->media->purgeTrash()['files']);
        $this->clock->time += 2 * 86_400;
        self::assertSame(1, $this->media->purgeTrash()['files']);
        self::assertFileDoesNotExist($oldFile);

        $this->expectException(MediaError::class);
        $this->media->replace($this->f->editor, $id, 'x.png', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>');
    }

    // ---- SVG

    private const string SVG_OK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 60"><title>Section</title><defs><linearGradient id="g"><stop offset="0" stop-color="#2F338A"/></linearGradient></defs><g fill="url(#g)" stroke="#000" stroke-width="2"><path d="M0 0 L100 0 L100 50 Z"/><rect x="5" y="5" width="20" height="10"/><circle cx="60" cy="30" r="10"/><text x="2" y="55" font-size="8">d = 25 mm</text></g></svg>';

    public function testOnlyTheOwnerUploadsSvgAndASafeOneKeepsItsDrawing(): void
    {
        try {
            $this->media->upload($this->f->editor, 'drawing.svg', self::SVG_OK);
            self::fail('an Editor uploaded SVG');
        } catch (MediaError $e) {
            self::assertSame('forbidden', $e->reason);
        }
        $r = $this->media->upload($this->f->owner, 'drawing.svg', self::SVG_OK);
        self::assertSame([], $r['removed']);
        $row = $this->media->find($r['id']);
        self::assertNotNull($row);
        self::assertSame(['svg', 'image/svg+xml', 120, 60], [$row['kind'], $row['mime'], self::i($row['width']), self::i($row['height'])]);
        $stored = (string) file_get_contents($this->storage->path(self::i($row['year']), self::s($row['sha256']), 'svg'));
        self::assertSame($row['sha256'], hash('sha256', $stored));
        foreach (['<path', '<rect', '<circle', '<text', 'url(#g)', 'd = 25 mm'] as $kept) {
            self::assertStringContainsString($kept, $stored);
        }
    }

    /** @return iterable<string, array{string, list<string>}> sample, strings that must not survive */
    public static function attacks(): iterable
    {
        $head = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">';
        yield 'script element' => [$head . '<script>alert(1)</script><rect width="1" height="1"/></svg>', ['script', 'alert']];
        yield 'onload and onclick handlers' => [$head . '<rect width="1" height="1" onclick="alert(1)" onmouseover="x()"/><g onload="x()"/></svg>', ['onclick', 'onload', 'onmouseover', 'alert']];
        yield 'javascript href' => [$head . '<a xlink:href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>', ['javascript', '<a']];
        yield 'foreignObject with html' => [$head . '<foreignObject width="10" height="10"><iframe src="https://evil.test"/></foreignObject></svg>', ['foreignObject', 'iframe', 'evil.test']];
        yield 'external image' => [$head . '<image href="https://evil.test/track.png" width="5" height="5"/></svg>', ['image', 'evil.test']];
        yield 'data uri image' => [$head . '<image href="data:image/svg+xml;base64,PHN2Zz4=" width="5" height="5"/></svg>', ['data:', '<image']];
        yield 'use of an external file' => [$head . '<use href="https://evil.test/a.svg#x"/><use xlink:href="//evil.test/a.svg#x"/></svg>', ['evil.test']];
        yield 'style element with import and url' => [$head . '<style>@import url(https://evil.test/x.css); rect{fill:url(https://evil.test/f)}</style><rect width="1" height="1"/></svg>', ['style', 'evil.test', '@import']];
        yield 'style attribute' => [$head . '<rect width="1" height="1" style="fill:url(https://evil.test/f);behavior:url(x.htc)"/></svg>', ['style=', 'evil.test', 'behavior']];
        yield 'external url() paint' => [$head . '<rect width="1" height="1" fill="url(https://evil.test/p#a)"/><rect width="1" height="1" stroke="url(//evil.test/p)"/></svg>', ['evil.test']];
        yield 'animate setting an href' => [$head . '<a href="#"><animate attributeName="href" values="javascript:alert(1)" dur="1s"/><set attributeName="href" to="javascript:alert(1)"/></a></svg>', ['animate', '<set', 'javascript']];
        yield 'nested svg with script and event' => [$head . '<svg onload="alert(1)"><script>1</script></svg></svg>', ['onload', 'script', 'alert']];
        yield 'embed object iframe' => [$head . '<object data="https://evil.test/x"/><embed src="https://evil.test/y"/><iframe src="https://evil.test/z"/></svg>', ['object', 'embed', 'iframe', 'evil.test']];
        yield 'comment and processing instruction' => [$head . '<!-- <script>x</script> --><?php echo 1; ?><rect width="1" height="1"/></svg>', ['<!--', '<?php', 'script']];
        yield 'entity-escaped javascript' => [$head . '<a href="&#106;avascript:alert(1)"><rect width="1" height="1"/></a></svg>', ['alert', '&#']];
        yield 'xml-stylesheet' => ['<?xml version="1.0"?><?xml-stylesheet href="https://evil.test/s.css"?>' . $head . '</svg>', []];
    }

    /**
     * @param list<string> $gone
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('attacks')]
    public function testAttackSamplesNeverSurviveSanitising(string $sample, array $gone): void
    {
        try {
            $result = SvgSanitizer::clean($sample, new MediaLimits());
        } catch (MediaError) {
            $this->addToAssertionCount(1); // refused outright

            return;
        }
        foreach ($gone as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $result->svg, $needle);
        }
        self::assertNotSame([], $result->removed, 'the Owner is told what was removed');
        self::assertStringNotContainsString('evil.test', $result->svg);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="1" height="1"/></svg>';
        yield 'external entity (XXE)' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><text>&x;</text></svg>'];
        yield 'entity expansion (billion laughs)' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><text>&b;</text></svg>'];
        yield 'doctype alone' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . $svg];
        yield 'compressed svgz' => ["\x1f\x8b" . gzencode($svg)];
        yield 'not svg root' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>'];
        yield 'svg in another namespace' => ['<svg xmlns="http://example.test/ns" viewBox="0 0 1 1"/>'];
        yield 'not xml' => ['<svg viewBox="0 0 1 1"><rect></svg>'];
        yield 'no size' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'];
        yield 'invalid utf-8' => ["<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1 1\"><text>\xC3\x28</text></svg>"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testHostileOrMalformedSvgIsRefused(string $bytes): void
    {
        $this->expectException(MediaError::class);
        SvgSanitizer::clean($bytes, new MediaLimits());
    }

    public function testSvgSizeDepthAndNodeCapsApply(): void
    {
        $this->expectException(MediaError::class);
        SvgSanitizer::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1">' . str_repeat('<g>', 50) . str_repeat('</g>', 50) . '</svg>', new MediaLimits());
    }

    public function testManyNodesAreRefused(): void
    {
        $this->expectException(MediaError::class);
        SvgSanitizer::clean('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1">' . str_repeat('<rect width="1" height="1"/>', 60) . '</svg>', new MediaLimits(maxSvgNodes: 50));
    }

    public function testASvgPassedOffAsARasterNameIsStillRoutedAsSvg(): void
    {
        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('Only the Owner can upload SVG');
        $this->media->upload($this->f->editor, 'photo.png', self::SVG_OK);
    }

    public function testImageProcessorReportsTypesByContent(): void
    {
        $clean = ImageProcessor::clean(self::png(10, 10), new MediaLimits());
        self::assertSame(['png', 'image/png'], [$clean['ext'], $clean['mime']]);
    }

    // ---- documents

    private static function pdf(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog {$extra}>>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    public function testAPdfIsCheckedByContentAndAttachedToExactlyOneDesignation(): void
    {
        $f = $this->f;
        $product = $f->products->createProduct($f->owner, $f->family, 'QZ 5');
        $variant = $f->products->addVariant($f->owner, $product, 'QZ 5-2RS', $f->source, true);
        $other = $f->products->createProduct($f->owner, $f->family, 'QZ 6');
        $f->products->addSpelling($f->owner, $product, null, 'QZ FIVE');

        $r = $this->documents->upload($f->editor, 'C:\\fakepath\\QZ5 datasheet.pdf', self::pdf(), 'برگه داده', 'Datasheet');
        $doc = $this->documents->find($r['id']);
        self::assertNotNull($doc);
        self::assertSame(['QZ5 datasheet.pdf', 'application/pdf', 'برگه داده'], [$doc['original_name'], $doc['mime'], $doc['title_fa']]);
        self::assertSame(\strlen(self::pdf()), self::i($doc['byte_size']), 'the size is recorded for display before download');
        self::assertFileExists($this->storage->path(self::i($doc['year']), self::s($doc['sha256']), 'pdf'));

        $this->documents->link($f->editor, $r['id'], 'qz 5', 'both');
        $this->documents->link($f->editor, $r['id'], 'QZ 5-2RS', 'fa', 'drawing');
        $links = Sql::rows($f->pdo, 'SELECT id, product_id, variant_id FROM document_link WHERE document_id = ? ORDER BY id', [$r['id']]);
        self::assertCount(2, $links);
        self::assertSame([$product, null], [self::i($links[0]['product_id']), $links[0]['variant_id']]);
        self::assertSame([null, $variant], [$links[1]['product_id'], self::i($links[1]['variant_id'])], 'the variant has its own link: nothing is shared or copied');
        self::assertSame(0, Sql::int($f->pdo, 'SELECT COUNT(*) FROM document_link WHERE product_id = ?', [$other]));

        foreach (['QZ 5', 'qz5'] as $dup) {
            try {
                $this->documents->link($f->editor, $r['id'], $dup, 'both');
                self::fail('linked twice');
            } catch (MediaError $e) {
                self::assertSame('duplicate', $e->reason);
            }
        }
        foreach (['QZ FIVE', 'QZ 99', ''] as $bad) {
            try {
                $this->documents->link($f->editor, $r['id'], $bad, 'fa');
                self::fail("linked {$bad}");
            } catch (MediaError $e) {
                self::assertSame('not_found', $e->reason);
            }
        }
        try {
            $this->documents->trash($f->editor, $r['id']);
            self::fail('trashed while linked');
        } catch (MediaError $e) {
            self::assertSame('referenced', $e->reason);
        }
        foreach ($links as $l) {
            $this->documents->unlink($f->editor, self::i($l['id']));
        }
        $this->documents->trash($f->editor, $r['id']);
        self::assertSame([], $this->documents->list(false, ''));
        self::assertSame(['existing' => true], ['existing' => $this->documents->upload($f->editor, 'again.pdf', self::pdf(), null, null)['existing']], 'the same bytes restore the same document');
    }

    /** @return iterable<string, array{string}> */
    public static function badPdfs(): iterable
    {
        yield 'not a pdf' => ['hello world'];
        yield 'truncated' => ["%PDF-1.4\n1 0 obj\n<< >>\nendobj\n"];
        yield 'javascript' => [self::pdf('/JavaScript (app.alert(1)) ')];
        yield 'js short name' => [self::pdf('/JS (x) ')];
        yield 'launch action' => [self::pdf('/Launch ')];
        yield 'open action' => [self::pdf('/OpenAction 2 0 R ')];
        yield 'embedded file' => [self::pdf('/EmbeddedFile ')];
        yield 'executable renamed' => ["MZ\x90\x00" . str_repeat('A', 100)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPdfs')]
    public function testUnsafeOrBrokenPdfsAreRefused(string $bytes): void
    {
        $this->expectException(MediaError::class);
        $this->documents->upload($this->f->editor, 'x.pdf', $bytes, null, null);
    }

    public function testMediaChangesAreAudited(): void
    {
        $id = $this->media->upload($this->f->editor, 'a.png', self::png(30, 30))['id'];
        $this->media->trash($this->f->editor, $id);
        $this->media->restore($this->f->editor, $id);
        $actions = array_map(strval(...), Sql::column($this->f->pdo, "SELECT action FROM audit_event WHERE action LIKE 'media.%' ORDER BY id"));
        self::assertSame(['media.upload', 'media.trash', 'media.restore'], $actions);
    }
}
