<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Content\ContentService;
use Nova\Content\RichText\BrokenReferences;
use Nova\Content\RichText\Renderer;
use Nova\Content\RichText\Schema;
use Nova\Core\Sql;
use Nova\Designation\DesignationIndex;
use Nova\Designation\InvalidDesignationIndex;
use Nova\Provenance\PageVerification;
use PDO;

/**
 * The publish validation gate (05 §7, A7 frozen and extended). Blockers stop
 * the run; warnings are reported. Runs on the same snapshot as the build.
 * There is deliberately no size or request budget check (D14/D17).
 */
final readonly class Validator
{
    public function __construct(private PDO $pdo, private bool $development) {}

    /** @return array{blockers: list<string>, warnings: list<string>} */
    public function check(SiteData $data): array
    {
        $blockers = [];
        $warnings = [];

        // Duplicate slug or designation key (the database prevents both; this re-proves it for the release).
        try {
            DesignationIndex::fromArray($data->index);
        } catch (InvalidDesignationIndex $e) {
            $blockers[] = 'designation index: ' . $e->getMessage();
        }

        // Required fields per language.
        foreach ($data->families as $f) {
            foreach (['fa', 'en'] as $lang) {
                if (trim($f[$lang]) === '' || trim($f['def_' . $lang]) === '') {
                    $blockers[] = "family {$f['slug']}: missing {$lang} name or definition";
                }
            }
        }

        $slugs = [];
        foreach ($data->products as $p) {
            $slugs[$p['slug']] = true;
        }
        foreach ($data->products as $p) {
            $label = "Product {$p['designation']}";
            // Rich content: schema, then every internal reference must resolve in this release.
            foreach (['fa', 'en'] as $lang) {
                $doc = $lang === 'fa' ? $p['description']['fa'] : $p['description']['en'];
                if ($doc === null) {
                    continue;
                }
                $errors = Schema::errors($doc, ContentService::PRODUCT_DESCRIPTION);
                if ($errors !== []) {
                    $blockers[] = "{$label}: {$lang} description is not valid rich content (" . implode('; ', $errors) . ')';
                    continue;
                }
                try {
                    Renderer::render($doc, ContentService::PRODUCT_DESCRIPTION, new SiteRenderContext($lang, $slugs));
                } catch (BrokenReferences $e) {
                    $blockers[] = "{$label}: {$lang} description has broken references (" . implode(', ', $e->references) . ')';
                }
            }
            $hasFa = $p['description']['fa'] !== null;
            if ($hasFa !== ($p['description']['en'] !== null)) {
                $warnings[] = "{$label}: the description exists in one language only";
            }
            // A page marked verified must still meet the verification rules (05 §11).
            if ($p['verified']) {
                $issues = (new PageVerification($this->pdo, new \Nova\Audit\AuditLog($this->pdo)))->issues($p['id']);
                if ($issues !== []) {
                    $blockers[] = "{$label}: marked verified but " . implode('; ', $issues);
                }
            }
            if ($p['items'] === []) {
                $warnings[] = "{$label}: no listed item; published for reference and noindex";
            }
        }

        // Relationship claims must be verified (05 §7): a published variant or spelling needs its confirmation.
        $samples = $this->development ? 1 : 0;
        foreach (Sql::rows($this->pdo, "SELECT p.designation AS product, v.designation FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.status = 'published' AND v.status = 'published' AND v.relationship_confirmed_at IS NULL AND NOT (p.is_sample = 1 AND ? = 1)", [$samples]) as $row) {
            $blockers[] = "variant {$row['designation']} of {$row['product']}: relationship not confirmed by the Owner with a source";
        }
        foreach (Sql::rows($this->pdo, "SELECT s.spelling FROM spelling s LEFT JOIN product_variant v ON v.id = s.variant_id JOIN product p ON p.id = COALESCE(s.product_id, v.product_id) WHERE p.status = 'published' AND s.confirmed_at IS NULL AND NOT (p.is_sample = 1 AND ? = 1)", [$samples]) as $row) {
            $blockers[] = "spelling {$row['spelling']}: not confirmed by the Owner";
        }

        // Samples can never be published outside development.
        if (!$this->development && Sql::int($this->pdo, 'SELECT COUNT(*) FROM product WHERE is_sample = 1') > 0) {
            $blockers[] = 'the database contains SAMPLE products; remove them before publishing outside development';
        }

        return ['blockers' => $blockers, 'warnings' => $warnings];
    }

    /**
     * After the build: every sitemap URL is an indexable page, and every page the
     * sitemap lists says index (05 §7 "a sitemap URL that would be noindex").
     *
     * @return list<string> blockers
     */
    public static function checkOutput(string $releaseDir, BuildConfig $config): array
    {
        $blockers = SeoAudit::release($releaseDir, $config);
        foreach (['fa', 'en'] as $lang) {
            $sitemap = (string) @file_get_contents("{$releaseDir}/{$lang}/sitemap.xml");
            preg_match_all('#<loc>([^<]+)</loc>#', $sitemap, $m);
            foreach ($m[1] as $url) {
                $path = substr(html_entity_decode($url, ENT_XML1), \strlen($config->hosts[$lang]));
                $file = "{$releaseDir}/{$lang}" . rtrim($path, '/') . '/index.html';
                $html = (string) @file_get_contents($path === '/' ? "{$releaseDir}/{$lang}/index.html" : $file);
                if ($html === '') {
                    $blockers[] = "sitemap ({$lang}) lists {$path}, which was not built";
                } elseif (!str_contains($html, '<meta name="robots" content="index, follow">')) {
                    $blockers[] = "sitemap ({$lang}) lists {$path}, which is noindex";
                }
            }
        }

        return $blockers;
    }
}
