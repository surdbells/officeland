<?php

declare(strict_types=1);

namespace App\Importers\BrandSite\Adapters;

use App\Importers\BrandSite\AbstractBrandAdapter;
use App\Importers\BrandSite\BrandHttp;
use App\Importers\BrandSite\ImportedProduct;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HAVIT from its official B2B showroom, havitsmart.com (Shopify; havit.hk
 * redirects there).
 *
 * The catalogue comes from /products.json (three requests for ~650 products),
 * which tolerates a request every few seconds. Spec tables and highlight
 * bullets exist only on the product HTML pages, and those are rate-limited hard
 * (429 after ~10 quick requests, blocked for about an hour). So:
 *
 *   default   import from the feed: name, model (parsed from the title - the
 *             feed's SKU is always null), category, images. No page fetches.
 *   --deep    additionally fetch each product page for specs and bullets, at
 *             one page every 90 seconds (an overnight job for the full set).
 *
 * 58 listings reuse another model's photos; when no image filename carries the
 * product's own model code the images are withheld rather than mis-attached.
 */
final class HavitAdapter extends AbstractBrandAdapter
{
    private const BASE = 'https://havitsmart.com';

    private const PAGE_DELAY_MS = 90000;

    /** @var array<string, array<string, mixed>> handle => feed product */
    private array $feed = [];

    private ?BrandHttp $pageHttp = null;

    /** [title regex, taxonomy path, label] - first match wins; "mouse pad" etc. are excluded below. */
    private const MAP = [
        ['#webcam#i', '/smart-tech/conferencing-av/webcams/', 'webcam'],
        ['#keyboard|combo|\bmouse\b|\bmice\b#i', '/it-products/computer-accessories/keyboards-mice/', 'keyboard/mouse'],
        ['#headphone|headset|earbud|\btws\b|\bows\b|neckband|earphone#i', '/it-products/computer-accessories/headsets-audio/', 'audio'],
        ['#speaker|soundbar#i', '/it-products/computer-accessories/headsets-audio/', 'speaker'],
        ['#power ?bank|charger|\bgan\b#i', '/it-products/computer-accessories/chargers-power-banks/', 'charging'],
        ['#\bhub\b|docking#i', '/it-products/computer-accessories/hubs-docking-stations/', 'hub'],
        ['#\bcable\b#i', '/it-products/computer-accessories/cables-adapters/', 'cable'],
        ['#cooling pad|cooling fan|laptop stand#i', '/it-products/computer-accessories/stands-mounts/', 'cooling/stand'],
        ['#smart ?watch#i', '/smart-tech/wearables/smart-watches/', 'smart watch'],
    ];

    private const SKIP = '#mouse ?pad|gaming (chair|table)|chassis|projector|tripod|hair dryer|massage|selfie|holder|smart ring|screenless|smart glasses|microphone|game ?pad|cleaner|kids|\bkw\d#i';

    public function key(): string
    {
        return 'havit';
    }

    public function brandName(): string
    {
        return 'Havit';
    }

    public function discover(BrandHttp $http): iterable
    {
        $this->loadFeed($http);

        $seen = [];
        foreach ($this->feed as $handle => $p) {
            $title = (string) ($p['title'] ?? '');
            // Regional duplicates and re-listings.
            if (preg_match('#for russia|-RU\b|-TUR\b#i', $title) || preg_match('#-(copy|1)$#', $handle)
                || ($p['images'] ?? []) === [] || preg_match(self::SKIP, $title)) {
                continue;
            }
            $model = $this->model($title);
            if ($model !== null && isset($seen[$model])) {
                continue;
            }
            $seen[$model ?? $handle] = true;

            yield $handle;
        }
    }

    private function loadFeed(BrandHttp $http): void
    {
        if ($this->feed !== []) {
            return;
        }
        for ($page = 1; $page <= 10; $page++) {
            $batch = $http->json(self::BASE."/products.json?limit=250&page={$page}")['products'] ?? [];
            if ($batch === []) {
                break;
            }
            foreach ($batch as $product) {
                $this->feed[(string) $product['handle']] = $product;
            }
        }
    }

    public function read(BrandHttp $http, string $reference): array
    {
        // --url passes a handle or a product URL.
        $reference = basename(rtrim((string) (parse_url($reference, PHP_URL_PATH) ?: $reference), '/'));
        $this->loadFeed($http);
        $p = $this->feed[$reference] ?? throw new RuntimeException("'{$reference}' is not in the products feed");

        $title = trim(preg_replace('/\s+/u', ' ', (string) $p['title']) ?? '');
        $path = null;
        $label = (string) ($p['product_type'] ?: (($p['tags'][0] ?? null) ?: 'untagged'));
        foreach (self::MAP as [$re, $taxonomy, $what]) {
            if (preg_match($re, $title)) {
                [$path, $label] = [$taxonomy, $what];
                break;
            }
        }

        $model = $this->model($title);
        $specs = [];
        $summary = null;

        if (($this->options['deep'] ?? false) === true && $path !== null) {
            [$specs, $summary] = $this->page($reference);
        }

        return [new ImportedProduct(
            name: Str::startsWith(Str::lower($title), 'havit') ? 'Havit'.substr($title, 5) : 'Havit '.$title,
            mpn: $model,
            categoryPath: $path,
            sourceCategory: $label,
            images: $this->images($p, $model),
            specs: $specs,
            summary: $summary,
            sourceUrl: self::BASE.'/products/'.$reference,
        )];
    }

    /** HV-N5080, MS601WB, KB271, FUXI-H3, HOUYI-M3C ... the first code in the title. */
    private function model(string $title): ?string
    {
        if (preg_match('#\b((?:FUXI|HOUYI|LIFE)\s?-?\s?[A-Z0-9]{2,5})\b#i', $title, $m)) {
            return strtoupper(str_replace(' ', '', $m[1]));
        }
        if (preg_match('#\b((?:HV-)?[A-Z]{1,5}\d{2,5}[A-Z]{0,4}d?)\b#i', $title, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /** @return list<string> */
    private function images(array $p, ?string $model): array
    {
        $images = array_values(array_filter((array) ($p['images'] ?? []), fn ($i): bool => (int) ($i['width'] ?? 0) >= 600));

        // Withhold images that belong to another model (no filename names this one).
        if ($model !== null) {
            $needle = Str::lower(str_replace('HV-', '', $model));
            $own = array_filter($images, fn ($i): bool => str_contains(Str::lower((string) $i['src']), $needle));
            if ($own === []) {
                return [];
            }
        }

        // Square packshots first; wide banners after.
        usort($images, fn ($a, $b): int => (int) (($b['width'] ?? 0) === ($b['height'] ?? 0)) <=> (int) (($a['width'] ?? 0) === ($a['height'] ?? 0)));

        return array_slice(array_map(fn ($i): string => strtok((string) $i['src'], '?').'?width=1600', $images), 0, 3);
    }

    /**
     * Specs table and highlight bullets from the product page, at the slow pace
     * this site requires.
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    private function page(string $handle): array
    {
        $this->pageHttp ??= new BrandHttp(self::PAGE_DELAY_MS, $this->userAgent());

        try {
            $html = $this->pageHttp->get(self::BASE.'/products/'.$handle);
        } catch (RuntimeException) {
            // 429 is expected if the pace is pushed; the feed data still imports.
            return [[], null];
        }

        return self::parsePage($html);
    }

    /**
     * The spec table is the first <table> after the features block (older pages
     * mark it .specs-table); the side-by-side compare table further down is not
     * this product's and is never read. "Model Number" is left out: the page
     * often lists sibling models there (MS689's says "MS1001/MS958").
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    public static function parsePage(string $html): array
    {
        $specs = [];
        $from = strpos($html, 'seo-features-content">');
        $segment = $from !== false ? substr($html, $from, 200000) : $html;
        if (preg_match('#<table[^>]*specs-table[^>]*>(.*?)</table>#s', $html, $t)
            || preg_match('#<table\s*>(.*?)</table>#s', $segment, $t)) {
            preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $t[1], $rows);
            foreach ($rows[1] as $row) {
                preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#s', $row, $cells);
                $label = rtrim(BrandHttp::text($cells[1][0] ?? ''), ': ');
                $value = BrandHttp::text($cells[1][1] ?? '');
                if ($label !== '' && $value !== '' && ! preg_match('#^(category|model( number)?)$#i', $label)) {
                    $specs[$label] ??= $value;
                }
            }
        }

        $bullets = [];
        if (preg_match('#<div class="seo-features-content">(.*?)</div>#s', $html, $f)) {
            preg_match_all('#<li[^>]*>(.*?)</li>#s', $f[1], $li);
            $bullets = array_values(array_filter(array_map([BrandHttp::class, 'text'], $li[1])));
        }

        return [$specs, $bullets !== [] ? implode("\n", $bullets) : null];
    }
}
