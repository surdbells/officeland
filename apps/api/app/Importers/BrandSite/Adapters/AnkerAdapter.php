<?php

declare(strict_types=1);

namespace App\Importers\BrandSite\Adapters;

use App\Importers\BrandSite\AbstractBrandAdapter;
use App\Importers\BrandSite\BrandHttp;
use App\Importers\BrandSite\ImportedProduct;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Anker from its own storefronts (headless Shopify on Next.js):
 *
 *   www.anker.com       chargers, power banks, cables, adapters, hubs/docks
 *   www.ankersolix.com  portable power stations (Anker SOLIX)
 *   www.soundcore.com   AnkerWork / PowerConf webcams and speakerphones
 *
 * Every product page embeds the full Shopify product plus Anker's CMS metafields
 * in <script id="__NEXT_DATA__">. /products.json is not used: it is 404 on
 * www.anker.com and robots-disallowed on the Shopify origin.
 *
 * One product per model (A2687), not per colour/length SKU: a reseller quotes
 * the model, and 600 near-identical variant pages would be thin content.
 *
 * Specs come from, in order: the productSpecs component, SOLIX's grouped Specs
 * list, the variant key_parameter summary, then the "Specifications:" block of
 * the HTML container. Some pages genuinely have none (specs live in infographic
 * images); the product then imports with the tags-derived facts only.
 */
final class AnkerAdapter extends AbstractBrandAdapter
{
    private const CHARGERS = '/it-products/computer-accessories/chargers-power-banks/';

    private const CABLES = '/it-products/computer-accessories/cables-adapters/';

    private const HUBS = '/it-products/computer-accessories/hubs-docking-stations/';

    private const STATIONS = '/smart-tech/power-backup/';

    private const WEBCAMS = '/smart-tech/conferencing-av/webcams/';

    private const SPEAKERPHONES = '/smart-tech/conferencing-av/conference-speakerphones/';

    /** AnkerWork lives on soundcore.com; these are its product handles. */
    private const ANKERWORK = ['a3369', 'ankerwork-c310-webcam', 'a3301', 'a3302', 'a3305', 'a3308011', 'a3311-ankerwork-sr500-conference-speaker'];

    private const SKIP_HANDLE = '#bundle|blind|combo|mystery|-f0$|-boa$|-2$|2-pack|3-pack|gift|member|sticker|greeting|card|bag|backpack|seel|worry-free|pet|chair|cup|blanket|valorant|wukong|customizable|screen-protector|receiver#i';

    public function key(): string
    {
        return 'anker';
    }

    public function brandName(): string
    {
        return 'Anker';
    }

    public function minDelayMs(): int
    {
        // Pages are ~1.7 MB and slow to build; anker.com/agents.md asks for restraint.
        return 4000;
    }

    public function discover(BrandHttp $http): iterable
    {
        foreach ($http->sitemapUrls('https://www.anker.com/server-sitemap-index-products.xml', '#^https://www\.anker\.com/products/[^/]+$#') as $url) {
            if (! preg_match(self::SKIP_HANDLE, basename($url))) {
                yield $url;
            }
        }

        $station = '#^https://www\.ankersolix\.com/products/(c\d{3,4}|c\d{3,4}-dc|c300x[\w-]*|f\d{4}(-plus)?|s\d{4}[\w-]*portable-power-station|s2000|s5000[\w-]*|5\d\d|a17[0-9a-z]{2,3}|c1000-gen2|c2000-gen2)$#i';
        foreach ($http->sitemapUrls('https://www.ankersolix.com/server-sitemap-index-products.xml', $station) as $url) {
            if (! preg_match('#expansion-battery|solar-panel|-kit|-cover|-2$|-3$|everfrost#i', $url)) {
                yield $url;
            }
        }

        foreach (self::ANKERWORK as $handle) {
            yield "https://www.soundcore.com/products/{$handle}";
        }
    }

    public function read(BrandHttp $http, string $reference): array
    {
        $html = $http->get($reference);
        $data = $this->nextData($html);
        if ($data === null) {
            // A transient render occasionally omits the blob; one retry.
            $data = $this->nextData($http->get($reference)) ?? throw new RuntimeException('no __NEXT_DATA__');
        }

        $props = $data['props']['pageProps'] ?? [];
        $p = $props['product'] ?? null;
        if (! is_array($p)) {
            return [];
        }

        $title = BrandHttp::text((string) ($p['title'] ?? ''));
        if ($title === '' || preg_match('#\b(soundcore|eufy|nebula)\b#i', (string) ($p['vendor'] ?? '').' '.$title) && ! str_contains($reference, 'soundcore.com')) {
            return [];
        }

        $variants = array_values(array_filter(
            (array) ($p['variants'] ?? []),
            fn ($v): bool => is_array($v) && ! preg_match('#^(BUNDLE|COMBO|MYSTERY)|-F0$#i', (string) ($v['sku'] ?? '')),
        ));
        $first = $variants[0] ?? [];

        $mpn = $this->model($html, $variants, $reference);
        $tags = array_map('strtolower', (array) ($p['tags'] ?? []));
        $collections = array_map(fn ($c): string => strtolower((string) ($c['handle'] ?? '')), (array) ($p['collections']['nodes'] ?? []));
        [$path, $label] = $this->category($reference, $title, $mpn, $tags, $collections);

        return [new ImportedProduct(
            name: Str::startsWith(Str::lower($title), 'anker') ? $title : 'Anker '.$title,
            mpn: $mpn,
            categoryPath: $path,
            sourceCategory: $label,
            images: $this->images($p, $first),
            specs: $this->specs($props, $first, $tags),
            summary: $this->summary($p),
            sourceUrl: $reference,
            ean: ($first['barcode'] ?? null) ?: null,
        )];
    }

    /**
     * The __NEXT_DATA__ JSON, found by string search: the page is ~1.7 MB and a
     * lazy regex across it exceeds PCRE's backtrack limit (preg_match then
     * returns false, silently).
     *
     * @return array<string, mixed>|null
     */
    private function nextData(string $html): ?array
    {
        $marker = strpos($html, 'id="__NEXT_DATA__"');
        $start = $marker === false ? false : strpos($html, '>', $marker);
        $end = $start === false ? false : strpos($html, '</script>', $start);
        if ($start === false || $end === false) {
            return null;
        }
        $data = json_decode(substr($html, $start + 1, $end - $start - 1), true);

        return is_array($data) ? $data : null;
    }

    /** Model number: the page's own "Model Number:" line, else the SKU stem (A2687141 -> A2687). */
    private function model(string $html, array $variants, string $url): ?string
    {
        if (preg_match('#Model Number:\s*([A-Z][0-9A-Z]{4})#', $html, $m)) {
            return $m[1];
        }
        foreach ($variants as $v) {
            if (preg_match('#^[ABG]([0-9A-Z]{4})#', (string) ($v['sku'] ?? ''), $m)) {
                return 'A'.$m[1];
            }
        }

        return preg_match('#/products/(a[0-9][0-9a-z]{3})#i', $url, $m) ? strtoupper($m[1]) : null;
    }

    /** @return array{0: ?string, 1: string} */
    private function category(string $url, string $title, ?string $mpn, array $tags, array $collections): array
    {
        $t = Str::lower($title);
        $signals = implode(' ', array_merge($tags, $collections));

        if (str_contains($url, 'ankersolix.com')) {
            return [self::STATIONS, 'power station'];
        }
        if (str_contains($url, 'soundcore.com')) {
            return str_contains($t, 'webcam') || preg_match('#\bc\d{3}\b#', $t) ? [self::WEBCAMS, 'ankerwork webcam'] : [self::SPEAKERPHONES, 'ankerwork speakerphone'];
        }
        // The title is the strongest signal - Anker files chargers under dock and
        // laptop collections too - so it decides first; tags only break ties.
        $byTitle = [
            ['#power bank|powercore|magnetic battery|\bbattery\b#', self::CHARGERS, 'power bank'],
            ['#charger|charging station|power strip#', self::CHARGERS, 'charger'],
            ['#\bhub\b|docking station|\bdock\b#', self::HUBS, 'hub/dock'],
            ['#\bcable\b#', self::CABLES, 'cable'],
            ['#adapter|hdmi switch#', self::CABLES, 'adapter'],
        ];
        foreach ($byTitle as [$re, $path, $label]) {
            if (preg_match($re, $t)) {
                return [$path, $label];
            }
        }

        $byTags = [
            ['#power ?bank|2b-battery#', self::CHARGERS, 'power bank'],
            ['#charger|wireless|car-charger#', self::CHARGERS, 'charger'],
            ['#hub|dock#', self::HUBS, 'hub/dock'],
            ['#\bcable#', self::CABLES, 'cable'],
            ['#adapter#', self::CABLES, 'adapter'],
        ];
        foreach ($byTags as [$re, $path, $label]) {
            if (preg_match($re, $signals)) {
                return [$path, $label];
            }
        }

        return [null, $tags[0] ?? ($collections[0] ?? 'untagged')];
    }

    /**
     * Clean studio shots only: the variant image, then square images (the
     * 4:5 portrait files are text-on-dark infographics). Capped at 2000 px.
     *
     * @return list<string>
     */
    private function images(array $p, array $first): array
    {
        $urls = [];
        if (! empty($first['image']['url'])) {
            $urls[] = (string) $first['image']['url'];
        }
        foreach ((array) ($p['images'] ?? []) as $img) {
            $w = (int) ($img['width'] ?? 0);
            $h = (int) ($img['height'] ?? 0);
            if (! empty($img['url']) && $w > 0 && $w === $h) {
                $urls[] = (string) $img['url'];
            }
        }

        return array_slice(array_values(array_unique(array_map(
            fn (string $u): string => $u.(str_contains($u, '?') ? '&' : '?').'width=2000',
            $urls,
        ))), 0, 3);
    }

    /** @return array<string, string> */
    private function specs(array $props, array $first, array $tags): array
    {
        $out = [];
        $components = (array) ($props['metafields']['components'] ?? []);

        foreach ((array) ($components['productSpecs']['items'] ?? []) as $row) {
            $this->put($out, $row['name'] ?? '', $row['presentation'] ?? '');
        }

        foreach ((array) ($components['Specs']['list'] ?? []) as $group) {
            foreach ((array) ($group['info'] ?? []) as $row) {
                $label = trim(((string) ($group['tit'] ?? '')).' '.((string) ($row['key'] ?? '')));
                $this->put($out, $label, $row['value'] ?? '');
            }
        }

        foreach ((array) ($first['metafields']['infos']['key_parameter']['list'] ?? []) as $row) {
            $value = $row['value'] ?? implode(', ', array_map(
                fn ($v): string => trim(((string) ($v['name'] ?? '')).' '.((string) ($v['label'] ?? ''))),
                (array) ($row['valueList'] ?? []),
            ));
            $this->put($out, $row['key'] ?? '', $value);
        }

        if ($out === [] && is_string($components['htmlContainer'] ?? null)) {
            $text = BrandHttp::text(preg_replace('#<br\s*/?>#i', "\n", $components['htmlContainer']));
            if (preg_match('#Specifications:?(.*)$#is', $text, $m)) {
                preg_match_all('#([A-Z][A-Za-z /()-]{2,40}):\s*([^:]{1,80}?)(?=\s+[A-Z][A-Za-z /()-]{2,40}:|$)#', $m[1], $rows, PREG_SET_ORDER);
                foreach ($rows as $r) {
                    $this->put($out, $r[1], $r[2]);
                }
            }
        }

        // Facts the tags carry ("Length:6ft", "Color:Black").
        foreach ($tags as $tag) {
            if (preg_match('#^(length|color|colour|material|capacity|wattage):\s*(.+)$#i', $tag, $m)) {
                $this->put($out, ucfirst($m[1]), $m[2]);
            }
        }

        return $out;
    }

    private function put(array &$out, mixed $label, mixed $value): void
    {
        $label = trim(BrandHttp::text((string) $label), " :\t");
        $value = trim(BrandHttp::text(str_ireplace(['</br>', '<br/>', '<br>', '<br />'], '; ', (string) $value)), " ;\t");
        if ($label !== '' && $value !== '' && ! isset($out[$label]) && mb_strlen($label) <= 60) {
            $out[$label] = $value;
        }
    }

    private function summary(array $p): ?string
    {
        $lines = [];
        foreach ((array) ($p['metafields']['productInfos']['key_features']['list'] ?? []) as $row) {
            if (! in_array((string) ($row['key'] ?? ''), ['What You Get', 'Warranty Note', 'Note'], true)) {
                $lines[] = trim(BrandHttp::text(($row['key'] ?? '').': '.($row['value'] ?? '')), ': ');
            }
        }
        $text = $lines !== [] ? implode("\n", $lines) : BrandHttp::text((string) ($p['description'] ?? ''));

        return $text !== '' ? $text : null;
    }
}
