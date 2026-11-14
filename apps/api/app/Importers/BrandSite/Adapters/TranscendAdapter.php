<?php

declare(strict_types=1);

namespace App\Importers\BrandSite\Adapters;

use App\Importers\BrandSite\AbstractBrandAdapter;
use App\Importers\BrandSite\BrandHttp;
use App\Importers\BrandSite\ImportedProduct;

/**
 * Transcend from its Nigeria site, ng.transcend-info.com (www redirects there).
 *
 * Server-rendered ASP.NET: no JSON or JSON-LD, so this reads HTML. The site's
 * firewall refuses curl's default User-Agent but accepts the engine's honest
 * one, so nothing is disguised.
 *
 * Shape: one storefront product per family x capacity ("Transcend 64GB
 * JetFlash 790"). The family page's order table is the source of truth for
 * part numbers; colour-only twins at the same capacity (TS64GJF790K / ...W)
 * become one product whose other part numbers are kept as a spec. Specs that
 * differ by capacity (speed, IOPS, TBW) are resolved for that capacity.
 * DDR modules have one page per part number and become one product each.
 *
 * The origin is slow (6-25 s a page) and drops connections under load, so
 * pages are fetched one at a time, 3 s apart, with retries.
 */
final class TranscendAdapter extends AbstractBrandAdapter
{
    private const BASE = 'https://ng.transcend-info.com';

    /** Listing slug => default taxonomy path; family pages may refine it by subcategory. */
    private const LISTINGS = [
        'usb' => '/it-products/storage-memory/flash-drives/',
        'portable-ssd' => '/it-products/storage-memory/external-hard-drives/',
        'external-hard-drive' => '/it-products/storage-memory/external-hard-drives/',
        'internal-ssd' => '/it-products/storage-memory/ssds-internal-drives/',
        'memory-card' => '/it-products/storage-memory/memory-cards/',
        'card-reader-accessory' => '/it-products/computer-accessories/hubs-docking-stations/',
    ];

    /** Product noun appended when the family name lacks one ("JetFlash 790" -> "... USB Flash Drive"). */
    private const NOUN = [
        'usb' => 'USB Flash Drive',
        'portable-ssd' => 'Portable SSD',
        'external-hard-drive' => 'External Hard Drive',
        'internal-ssd' => 'SSD',
        'memory-card' => 'Memory Card',
    ];

    private const DRAM = ['ddr5', 'ddr4', 'ddr3'];

    /** Subcategories (from the page title) that are not stocked. */
    private const SKIP_SUBCATEGORY = '#DVD|PCMCIA|Adapter$#i';

    /** Boilerplate spec rows. */
    private const SKIP_SPECS = '#^(warranty policy|note|notes?\s*\d*)$#i';

    public function key(): string
    {
        return 'transcend';
    }

    public function brandName(): string
    {
        return 'Transcend';
    }

    /**
     * ng.transcend-info.com sends its leaf certificate without the Sectigo
     * "Public Server Authentication CA OV R36" intermediate (browsers fetch it
     * from the AIA URL; OpenSSL fails with "unable to get local issuer").
     * resources/certs/sectigo-ov-r36.pem is that public intermediate, from
     * http://crt.sectigo.com/SectigoPublicServerAuthenticationCAOVR36.crt,
     * SHA-256 65:42:D1:76:..:B4:E7:85:30, valid to 2036-03-21.
     */
    public function extraCertificates(): array
    {
        return [resource_path('certs/sectigo-ov-r36.pem')];
    }

    public function minDelayMs(): int
    {
        return 3000;
    }

    public function discover(BrandHttp $http): iterable
    {
        $seen = [];
        foreach (array_keys(self::LISTINGS) as $listing) {
            $html = $http->get(self::BASE.'/product/'.$listing);
            preg_match_all("#<a href='(/product/[^/']+/[^/']+)'>\\s*<div class=\"product_list_img\">#", $html, $m);
            foreach ($m[1] as $path) {
                // Enclosures are cross-listed under two categories.
                $slug = basename($path);
                if (! isset($seen[$slug])) {
                    $seen[$slug] = true;

                    yield self::BASE.$path.'#'.$listing;
                }
            }
        }

        foreach (self::DRAM as $generation) {
            $html = $http->get(self::BASE.'/product/module/'.$generation);
            preg_match_all('#href="(/product/module/'.$generation.'/[^"]+)"#', $html, $m);
            foreach (array_unique($m[1]) as $path) {
                yield self::BASE.$path;
            }
        }
    }

    public function read(BrandHttp $http, string $reference): array
    {
        $url = explode('#', $reference, 2)[0];
        $listing = explode('#', $reference, 2)[1] ?? null;
        $html = $http->get($url);

        if (str_contains($url, '/product/module/')) {
            return $this->dram($html, $url);
        }

        if (! str_contains($html, 'product_order_pn')) {
            // Truncated page or not a product family.
            return [];
        }

        $listing ??= preg_match('#/product/([^/]+)/#', $url, $l) ? $l[1] : '';
        if ($listing === 'usb-flash-drive') {
            $listing = 'usb';
        }

        $family = BrandHttp::text(preg_match('#product_general_intro">\s*<h2>(.*?)</h2>#s', $html, $f) ? $f[1] : '');
        $subcategory = preg_match('#<title>[^|]*\|\s*(.*?)\s*-\s*Transcend#s', $html, $t) ? trim($t[1]) : '';
        if ($family === '' || preg_match(self::SKIP_SUBCATEGORY, $subcategory)) {
            return [];
        }

        $path = self::LISTINGS[$listing] ?? null;
        if (preg_match('#hub#i', $subcategory)) {
            $path = '/it-products/computer-accessories/hubs-docking-stations/';
        }

        $intro = BrandHttp::text(preg_match('#product_general_intro">\s*<h2>.*?</h2>\s*<p>(.*?)</p>#s', $html, $p) ? $p[1] : '');
        $features = $this->features($html);
        $summary = trim($intro.($features !== [] ? "\n".implode("\n", $features) : '')) ?: null;
        $images = $this->images($html);
        [$shared, $perCapacity] = $this->specs($html);

        $products = [];
        foreach ($this->orderTable($html) as $capacity => $parts) {
            $specs = ['Capacity' => $capacity] + $shared;
            foreach ($perCapacity as $label => $byCapacity) {
                if (isset($byCapacity[self::norm($capacity)])) {
                    $specs[$label] = $byCapacity[self::norm($capacity)];
                }
            }
            unset($specs['Capacity (all sizes)']);

            $mpns = array_keys($parts);
            $memos = array_filter($parts);
            if (count($mpns) > 1) {
                $specs['Part numbers'] = implode('; ', array_map(fn ($pn) => $pn.($parts[$pn] !== '' ? " ({$parts[$pn]})" : ''), $mpns));
            } elseif ($memos !== []) {
                $memo = (string) reset($memos);
                // One colour at this size: say that colour, not the family's list.
                if (isset($specs['Color']) && stripos($specs['Color'], $memo) !== false) {
                    $specs['Color'] = $memo;
                } else {
                    $specs['Variant'] = $memo;
                }
            }

            $capacityLabel = $capacity !== '-' ? $capacity.' ' : '';
            $noun = preg_match('#flash|ssd|drive|card|reader|hub|enclosure|kit|sd\b|cf#i', $family) ? '' : (self::NOUN[$listing] ?? '');
            $products[] = new ImportedProduct(
                name: trim("Transcend {$capacityLabel}{$family} {$noun}"),
                mpn: $mpns[0],
                categoryPath: $path,
                sourceCategory: $subcategory !== '' ? $subcategory : $listing,
                images: $images,
                specs: $specs,
                summary: $summary,
                sourceUrl: $url,
            );
        }

        return $products;
    }

    /**
     * Capacity => [part number => memo]. Accessories without a capacity use "-".
     *
     * @return array<string, array<string, string>>
     */
    private function orderTable(string $html): array
    {
        $start = strpos($html, 'row product_order');
        $segment = $start !== false ? substr($html, $start, 60000) : '';
        $out = [];
        foreach (array_slice(explode('partNum-list', $segment), 1) as $block) {
            $capacity = preg_match('#label-capacity-grayborder"[^>]*>\s*([^<]*?)\s*</span>#', $block, $c) ? strtoupper(str_replace(' ', '', $c[1])) : '';
            $capacity = $capacity !== '' ? $capacity : '-';
            preg_match_all('#product_order_pn">\s*([^<\s]+)\s*</span>\s*<span class="product_order_desc">(.*?)</span>\s*</span>#s', $block, $rows, PREG_SET_ORDER);
            foreach ($rows as $row) {
                $out[$capacity][strtoupper($row[1])] = BrandHttp::text($row[2]);
            }
        }

        return $out;
    }

    /**
     * Shared specs (label => value) and per-capacity specs (label => [capacity => value]).
     *
     * @return array{0: array<string, string>, 1: array<string, array<string, string>>}
     */
    private function specs(string $html): array
    {
        $start = strpos($html, 'row product_spec');
        if ($start === false) {
            return [[], []];
        }
        $end = strpos($html, 'row product_order', $start) ?: $start + 80000;
        $segment = substr($html, $start, $end - $start);

        $shared = [];
        $perCapacity = [];
        preg_match_all('#<th[^>]*>(.*?)</th>\s*<td[^>]*product_spec_detail[^>]*>(.*?)</td>#s', $segment, $rows, PREG_SET_ORDER);
        foreach ($rows as [, $label, $cell]) {
            $label = BrandHttp::text($label);
            if ($label === '' || preg_match(self::SKIP_SPECS, $label)) {
                continue;
            }

            if (str_contains($cell, 'vnull')) {
                preg_match_all('#<span class=vnull>([^<]*)</span>\s*([A-Z]+)</div>(.*?)</li>#s', $cell, $vals, PREG_SET_ORDER);
                foreach ($vals as [, $size, $unit, $value]) {
                    $value = BrandHttp::text($value);
                    if (trim($size) !== '' && preg_match('#\d#', $value)) {
                        $perCapacity[$label][self::norm($size.$unit)] = $value;
                    }
                }

                continue;
            }

            // Ticks are an image; "-" means no.
            $cell = preg_replace('#<img[^>]*check[^>]*>#i', 'Yes', $cell) ?? $cell;
            $parts = preg_match_all('#<li[^>]*>(.*?)</li>#s', $cell, $li) ? $li[1] : (preg_split('#<br\s*/?>#i', $cell) ?: []);
            $parts = array_filter(array_map(fn (string $p): string => BrandHttp::text($p), $parts), fn (string $v): bool => $v !== ''
                // Template leftovers such as "Double-sided: mm x mm x mm".
                && ! (str_contains($v, 'mm') && ! preg_match('#\d#', $v)));
            $value = implode('; ', $parts);
            if ($value === '-') {
                $value = 'No';
            }
            if ($value !== '') {
                $shared[$label === 'Capacity' ? 'Capacity (all sizes)' : $label] ??= $value;
            }
        }

        return [$shared, $perCapacity];
    }

    /** @return list<string> */
    private function features(string $html): array
    {
        if (preg_match('#Key Features\s*</h3>\s*<ul>(.*?)</ul>#s', $html, $k)) {
            preg_match_all('#<li[^>]*>(.*?)</li>#s', $k[1], $li);

            return array_values(array_filter(array_map([BrandHttp::class, 'text'], $li[1])));
        }
        preg_match_all('#product_feature_rich_content[^>]*>\s*<h3[^>]*>(.*?)</h3>#s', $html, $h);

        return array_values(array_filter(array_map([BrandHttp::class, 'text'], $h[1])));
    }

    /** @return list<string> */
    private function images(string $html): array
    {
        if (! preg_match('#class="carousel-inner">(.*?)product_general_intro#s', $html, $c)) {
            return [];
        }
        preg_match_all('#<img src="([^"]+/ModelPic/[^"]+)"#i', $c[1], $m);

        return array_slice(array_values(array_unique(array_map(
            fn (string $u): string => preg_replace('#^https?://cdn\.#i', 'https://cdn.', $u) ?? $u,
            $m[1],
        ))), 0, 3);
    }

    /** @return list<ImportedProduct> */
    private function dram(string $html, string $url): array
    {
        $pn = BrandHttp::text(preg_match('#class="P_Intro">.*?<h1>(.*?)</h1>#s', $html, $h) ? $h[1] : '');
        $headline = BrandHttp::text(preg_match('#class="P_Intro">.*?<h4>(.*?)</h4>#s', $html, $h) ? $h[1] : '');
        if ($pn === '' || $headline === '') {
            return [];
        }
        $capacity = BrandHttp::text(preg_match('#<li class="Capacity">(.*?)</li>#s', $html, $c) ? $c[1] : '');
        $device = BrandHttp::text(preg_match('#<li class="Type">(.*?)</li>#s', $html, $d) ? $d[1] : '');

        $specs = [];
        $start = strpos($html, 'class="DRAM_Spec"');
        if ($start !== false) {
            preg_match_all('#<div class="T_Cell SubText">(.*?)</div>\s*<div class="T_Cell">(.*?)</div>#s', substr($html, $start, 60000), $rows, PREG_SET_ORDER);
            foreach ($rows as [, $label, $value]) {
                $label = BrandHttp::text($label);
                $value = BrandHttp::text($value);
                if ($label !== '' && $value !== '' && ! preg_match(self::SKIP_SPECS, $label)) {
                    $specs[$label] ??= $value;
                }
            }
        }
        $specs = ['Capacity' => $capacity, 'Device' => $device] + $specs;

        $intro = BrandHttp::text(preg_match('#<div class="PD_Intro">\s*<p>(.*?)</p>#s', $html, $p) ? $p[1] : '');

        return [new ImportedProduct(
            name: trim("Transcend JetRam {$capacity} {$headline}").($device !== '' ? " ({$device})" : ''),
            mpn: strtoupper($pn),
            categoryPath: '/it-products/storage-memory/',
            sourceCategory: 'DRAM '.$headline,
            // The site has no per-module photos, only shared generation art.
            images: [],
            specs: array_filter($specs),
            summary: $intro !== '' ? $intro : null,
            sourceUrl: $url,
        )];
    }

    private static function norm(string $capacity): string
    {
        return strtoupper(str_replace(' ', '', $capacity));
    }
}
