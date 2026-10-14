<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Publish\AssetBuilder;
use Nova\Publish\BuildConfig;
use Nova\Publish\SiteBuilder;
use Nova\Publish\Strings;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The pages the endpoint answers with when JavaScript is off (or a script
 * request needs a page): the form again with the entered values and the
 * reasons, the confirmation step, and the "received" page. They use the
 * public site's own base template, strings and assets, so the visitor stays
 * on the frozen design (Gate 2) in their language and direction; noindex.
 */
final class InquiryView
{
    private readonly Environment $twig;

    /** @param array<string, string> $contact company contact values (i18n/contact.php) */
    public function __construct(
        private readonly string $appDir,
        string $assetsDir,
        private readonly BuildConfig $build,
        private readonly array $contact,
        private readonly bool $development,
    ) {
        $assets = AssetBuilder::manifest($assetsDir);
        $this->twig = new Environment(new FilesystemLoader($appDir . '/templates/public'), ['autoescape' => 'html', 'strict_variables' => true, 'cache' => false]);
        $this->twig->addFunction(new TwigFunction('asset', static fn(string $name): string => $assets[$name] ?? throw new RuntimeException("Unknown asset {$name}")));
        $this->twig->addFilter(new TwigFilter('fill', static fn(string $tpl, array $vars): string => SiteBuilder::fill($tpl, $vars)));
        $this->twig->addFilter(new TwigFilter('iso', static fn(string $s): string => "\u{2066}{$s}\u{2069}"));
    }

    /** @param array<string, mixed> $vars */
    public function render(string $lang, array $vars): string
    {
        $lang = $lang === 'en' ? 'en' : 'fa';
        $other = $lang === 'fa' ? 'en' : 'fa';
        $strings = Strings::load($this->appDir . '/i18n', $lang);
        $js = $strings['js'] + ['lang' => $lang, 'wa' => $this->contact['wa'] ?? '', 'tel' => $this->contact['tel1'] ?? ''];
        /** @var array<string, mixed> $t */
        $t = $strings['page'];

        return $this->twig->render('inquiry.html.twig', $vars + [
            'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'other_lang' => $other, 'subset' => $lang === 'fa' ? 'fa' : 'latin',
            't' => $t, 'js' => $js, 'js_strings' => SiteBuilder::json($js), 'home' => '/', 'c' => $this->contact + $strings['contact_show'],
            'dev_banner' => $this->development, 'preview' => false,
            'self_url' => $this->build->hosts[$lang], 'other_url' => $this->build->hosts[$other], 'hosts' => $this->build->hosts,
            'canonical' => null, 'robots' => 'noindex, nofollow',
            'page_title' => \is_string($t['inq_title'] ?? null) ? $t['inq_title'] : '', 'page_desc' => '',
        ]);
    }
}
