<?php

namespace App\Support;

/**
 * The site audit's issue catalogue: every issue type the analysis can flag,
 * its severity, and the plain-English "why it matters" and "how to fix" the
 * page shows. Code, not data: wording changes ship with the detection rules.
 *
 * Thresholds quoted here (60 characters, 2 seconds, …) are the defaults of
 * config/indlela.php `audit.thresholds`; `:placeholders` are filled from it.
 * Site-wide types (`site` true) count as fixed deductions in the health
 * score instead of per page (docs/API.md › Site audit).
 */
final class AuditIssues
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const NOTICE = 'notice';

    /** Highest first: the order of the issues list. */
    public const SEVERITIES = [self::CRITICAL, self::WARNING, self::NOTICE];

    /** Types that come from PageSpeed Insights (left out of comparisons when either audit has no vitals). */
    public const VITALS_TYPES = ['poor_core_web_vitals', 'needs_improvement_core_web_vitals'];

    /**
     * type => [severity, title, why, how to fix, site-wide].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4?: bool}>
     */
    private const TYPES = [
        // --- Status codes and redirects -------------------------------------------------------
        'page_5xx' => [self::CRITICAL, 'Server errors (5xx) or no response',
            'The server failed (or did not answer in time) when this page was requested. Visitors see an error and Google cannot index the page; if it keeps happening Google crawls the whole site less often.',
            'Open the page yourself. If it fails, check the hosting control panel for errors, a plugin that recently updated, or the PHP memory limit (WordPress: Tools › Site Health). Ask the host to look at the error log if you cannot find the cause.'],
        'page_4xx' => [self::CRITICAL, 'Broken pages (4xx)',
            'This URL answers "not found" (404) or another client error. Visitors land on a dead end and any links pointing here waste their value.',
            'If the page moved, add a 301 redirect to its new address (WordPress: the Redirection plugin, or Rank Math / Yoast Premium redirects). If it is gone for good, remove the links to it (see "Broken internal links") and take it out of the sitemap.'],
        'broken_internal_link' => [self::CRITICAL, 'Broken internal links',
            'A page links to another page (or image) on your own site that returns an error. Visitors hit a dead end and search engines stop following the trail.',
            'Edit the page and update or remove the link (in Elementor, check buttons, menus and footer widgets too). If many pages link to the same broken URL it is probably in the menu or footer: fix it once there. Or redirect the broken URL to the right page.'],
        'broken_external_link' => [self::WARNING, 'Broken external links',
            'A page links to another website and that page no longer exists. It is a poor experience for visitors and a sign the content is out of date.',
            'Edit the page and point the link to a working page, or remove it. Some sites block automated checks: open the link yourself before deleting it.'],
        'redirect_chain' => [self::WARNING, 'Redirect chains',
            'This URL redirects more than once before reaching a page (A → B → C). Each hop slows visitors down and search engines may stop following long chains.',
            'Change the first redirect so it goes straight to the final address, and update links to use the final address. In WordPress check the Redirection plugin (or Rank Math / Yoast redirects) and the .htaccess file for redirects that pile up, e.g. http → https → www.'],
        'redirect_loop' => [self::CRITICAL, 'Redirect loops',
            'This URL redirects in a circle (or through too many hops) and never reaches a page. Browsers show an error and the page cannot be indexed.',
            'Find the redirects involved (listed with the issue) and remove the one that points back. Common causes: conflicting redirect plugins, a wrong "Site Address" in WordPress Settings › General, or an SSL plugin fighting the host\'s https redirect.'],
        'internal_link_to_redirect' => [self::NOTICE, 'Internal links to redirects',
            'A page links to an address on your site that redirects elsewhere. It works, but every click and every crawl takes an extra step.',
            'Update the link to point straight at the final address (often an old http:// or non-www link in a menu, button or footer).'],

        // --- Titles, descriptions, headings ---------------------------------------------------
        'missing_title' => [self::CRITICAL, 'Missing page titles',
            'The page has no <title>. The title is the headline Google shows in results; without one Google makes one up and the page is less likely to be clicked.',
            'Set an SEO title for the page in Yoast SEO or Rank Math (the snippet editor under the page editor). Make it unique and describe what the page offers.'],
        'duplicate_title' => [self::WARNING, 'Duplicate page titles',
            'Several pages share the same title, so Google cannot tell them apart and they compete with each other in results.',
            'Give each page its own title in Yoast SEO or Rank Math. If the pages really are the same content, keep one and redirect (or canonicalise) the others to it.'],
        'title_too_long' => [self::NOTICE, 'Titles too long',
            'The title is longer than :title_max characters, so Google will probably cut it off with "…" in results.',
            'Shorten the SEO title in Yoast SEO or Rank Math to about 50–60 characters, with the most important words first.'],
        'title_too_short' => [self::NOTICE, 'Titles too short',
            'The title is shorter than :title_min characters: a missed chance to tell searchers (and Google) what the page is about.',
            'Expand the SEO title in Yoast SEO or Rank Math with the service, the place and your brand, e.g. "Plumber in Sandton | 24-hour call-outs | Acme Plumbing".'],
        'missing_meta_description' => [self::WARNING, 'Missing meta descriptions',
            'The page has no meta description, the text under the title in Google. Google then picks text from the page, which often reads badly and gets fewer clicks.',
            'Write a meta description (about 120–155 characters) in Yoast SEO or Rank Math that sums up the page and gives a reason to click.'],
        'duplicate_meta_description' => [self::WARNING, 'Duplicate meta descriptions',
            'Several pages share the same meta description, which makes them look alike in results.',
            'Write a unique description for each page in Yoast SEO or Rank Math. A template description set for all pages (theme or SEO plugin settings) often causes this.'],
        'meta_description_too_long' => [self::NOTICE, 'Meta descriptions too long',
            'The meta description is longer than :meta_description_max characters, so Google will cut it off.',
            'Trim it to about 120–155 characters in Yoast SEO or Rank Math, keeping the key message at the start.'],
        'missing_h1' => [self::WARNING, 'Missing H1 heading',
            'The page has no main (H1) heading. Visitors and search engines use it to understand what the page is about.',
            'Add one main heading at the top of the page. In Elementor, select the page\'s main Heading widget and set its HTML tag to H1; some themes hide the page title, so check the page settings too.'],
        'multiple_h1' => [self::NOTICE, 'More than one H1 heading',
            'The page has several H1 headings, which blurs what the page\'s main topic is.',
            'Keep one H1 for the page\'s main heading and change the others to H2 or H3 (in Elementor: the Heading widget\'s HTML tag). Themes that output the title as an H1 plus an Elementor H1 are a common cause.'],
        'images_missing_alt' => [self::WARNING, 'Images without alt text',
            'Images have no alt text. Screen readers cannot describe them and Google Images cannot understand them.',
            'Add a short description to each image: in the WordPress Media Library (Alt Text field) or in the Elementor Image widget. Purely decorative images can stay empty.'],

        // --- Indexing ----------------------------------------------------------------------------
        'noindex_in_sitemap' => [self::CRITICAL, 'Noindex pages in the sitemap',
            'The sitemap asks Google to index this page while the page itself says "noindex". Mixed signals waste crawl time and the page will not appear in Google.',
            'Decide whether the page should be in Google. If yes, remove the noindex (Yoast / Rank Math › Advanced › "Allow search engines to show this page"). If not, it will drop out of the sitemap once the SEO plugin regenerates it.'],
        'homepage_noindex' => [self::CRITICAL, 'Home page is set to noindex',
            'The home page tells search engines not to index it. It will drop out of Google, and with it most of your brand searches.',
            'In WordPress go to Settings › Reading and untick "Discourage search engines from indexing this site", then check the home page\'s Yoast / Rank Math advanced settings.', true],
        'noindex_linked_internally' => [self::WARNING, 'Noindex pages linked from the site',
            'Your own pages link to a page that is set to noindex. If the page matters it is missing from Google; if it does not, the links waste visitors\' and Google\'s attention.',
            'Check whether the noindex is intended. If the page should rank, remove the noindex in Yoast / Rank Math. If not, consider removing the links from menus and content.'],
        'canonical_to_non_200' => [self::CRITICAL, 'Canonical points to a broken or redirected page',
            'The page\'s canonical tag tells Google the "real" version lives at an address that is broken or redirects. Google may ignore the page or index the wrong one.',
            'Set the canonical URL to the working, final address of the page (Yoast / Rank Math › Advanced › Canonical URL), or clear it so the plugin uses the page\'s own address.'],
        'canonical_to_other_page' => [self::NOTICE, 'Canonical points to another page',
            'The page says another URL is the main version, so Google will usually index that one instead. Fine for true duplicates, a problem if this page should rank on its own.',
            'If this page should appear in Google, change its canonical to its own address in Yoast / Rank Math (Advanced › Canonical URL). If it really is a duplicate, no action is needed.'],
        'multiple_canonicals' => [self::WARNING, 'More than one canonical tag',
            'The page declares several canonical URLs. Google may ignore them all.',
            'Usually two plugins (or a theme and a plugin) both add a canonical. Keep one SEO plugin active and turn off the canonical option elsewhere.'],
        'missing_canonical' => [self::NOTICE, 'Missing canonical tag',
            'The page has no canonical tag. Variants of the address (with tracking parameters, http/https, trailing slash) can be indexed as separate duplicates.',
            'Yoast SEO and Rank Math add a self-referencing canonical automatically: make sure one of them is active and not disabled for this page type.'],
        'orphan_page' => [self::WARNING, 'Orphan pages',
            'The page is in the sitemap but no page on the site links to it. Visitors cannot find it and Google treats it as unimportant.',
            'Link to it from a relevant page, menu or category page. If the page is no longer needed, remove it (and redirect it) so it drops out of the sitemap.'],
        'sitemap_missing' => [self::WARNING, 'No XML sitemap found',
            'No sitemap was found in robots.txt or at the usual addresses. A sitemap helps Google discover every page, especially new ones.',
            'Turn on the XML sitemap in Yoast SEO (Settings › Site features) or Rank Math (Sitemap Settings), then submit it in Google Search Console.', true],
        'robots_blocks_site' => [self::CRITICAL, 'robots.txt blocks the site',
            'robots.txt tells search engines (or our crawler) not to visit the home page, so the site cannot be indexed or audited.',
            'Edit robots.txt (Yoast › Tools › File editor, or Rank Math › General › Edit robots.txt) and remove the "Disallow: /" line. Also check Settings › Reading › "Discourage search engines".', true],

        // --- Performance and content -------------------------------------------------------------
        'slow_response' => [self::NOTICE, 'Slow server response',
            'The server took more than :slow_seconds seconds to send the page. Slow pages lose visitors and rank lower.',
            'Turn on page caching (e.g. LiteSpeed Cache, WP Rocket or the host\'s cache), keep plugins to the ones you need, and ask the host about faster hosting or PHP version upgrades.'],
        'thin_content' => [self::NOTICE, 'Thin content',
            'The page has fewer than :thin_words words. Pages with little text rarely rank for anything competitive.',
            'Add useful, original text that answers what visitors want to know (services, prices, areas, FAQs). If the page is not meant to rank, consider setting it to noindex.'],
        'http_not_https' => [self::CRITICAL, 'Site not on HTTPS',
            'The site is served over plain http. Browsers mark it "Not secure", visitors leave, and Google prefers secure sites.',
            'Install an SSL certificate (most SA hosts include Let\'s Encrypt for free), update WordPress Settings › General to https, and redirect http to https (Really Simple SSL can help). Update the site\'s address in Indlela afterwards.', true],
        'poor_core_web_vitals' => [self::WARNING, 'Poor Core Web Vitals',
            'Google\'s PageSpeed test rates this page poor (a performance score under :poor_score, or real visitors\' loading, interactivity or layout stability in the "poor" band). Slow pages lose visitors and rankings.',
            'Compress and resize images (Smush, ShortPixel or Imagify; serve WebP), turn on caching and lazy loading, remove unused plugins and Elementor widgets, and limit fonts and scripts. Test again in PageSpeed Insights after each change.'],
        'needs_improvement_core_web_vitals' => [self::NOTICE, 'Core Web Vitals need improvement',
            'Google\'s PageSpeed test rates this page "needs improvement": not bad, but slower than it should be.',
            'Same fixes as poor vitals, starting with the biggest image above the fold (often an Elementor hero or slider) and render-blocking scripts.'],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function severity(string $type): string
    {
        return self::TYPES[$type][0];
    }

    public static function isSiteWide(string $type): bool
    {
        return self::TYPES[$type][4] ?? false;
    }

    /** @return array{type: string, severity: string, title: string, why: string, how_to_fix: string} */
    public static function describe(string $type): array
    {
        [$severity, $title, $why, $fix] = self::TYPES[$type];

        return ['type' => $type, 'severity' => $severity, 'title' => $title, 'why' => self::fill($why), 'how_to_fix' => self::fill($fix)];
    }

    /** @return list<array{type: string, severity: string, title: string, why: string, how_to_fix: string}> */
    public static function all(): array
    {
        return array_map(self::describe(...), self::types());
    }

    /** Severity rank for sorting: critical 0, warning 1, notice 2. */
    public static function rank(string $severity): int
    {
        return (int) array_search($severity, self::SEVERITIES, true);
    }

    private static function fill(string $text): string
    {
        $t = config('indlela.audit.thresholds');

        return strtr($text, [
            ':title_max' => (string) $t['title_max'],
            ':title_min' => (string) $t['title_min'],
            ':meta_description_max' => (string) $t['meta_description_max'],
            ':slow_seconds' => rtrim(rtrim(number_format($t['slow_response_ms'] / 1000, 1), '0'), '.'),
            ':thin_words' => (string) $t['thin_content_words'],
            ':poor_score' => (string) config('indlela.audit.vitals.poor_score'),
        ]);
    }
}
