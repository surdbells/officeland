<?php

declare(strict_types=1);

namespace App\Importers\BrandSite\Adapters;

use App\Importers\BrandSite\AbstractBrandAdapter;
use App\Importers\BrandSite\BrandHttp;
use App\Importers\BrandSite\ImportedProduct;

/**
 * Genius (KYE Systems) from its global site, www.geniusnet.com/en.
 *
 * Custom CMS, server-rendered, no JSON-LD: categories come from the list pages
 * (the sitemap misses ~40% of products), fields from the product page markup.
 * The site publishes models ("DX-111"), not part numbers or EANs, so the model
 * is the MPN here; the engine matches legacy rows by that code in their name.
 *
 * Gallery images sit behind hotlink protection: without a Referer from the
 * site they redirect to the home page, so image requests carry the page's
 * origin as Referer - the true context in which the images were found.
 *
 * The origin refuses connections for a long while after a burst, so requests
 * are 5 s apart. Gaming lines (/en/gaming/...) are not imported.
 */
final class GeniusAdapter extends AbstractBrandAdapter
{
    private const BASE = 'https://www.geniusnet.com';

    /** List slug => taxonomy path; accessories are decided per product slug. */
    private const LISTS = [
        'mice' => '/it-products/computer-accessories/keyboards-mice/',
        'keyboards' => '/it-products/computer-accessories/keyboards-mice/',
        'combos' => '/it-products/computer-accessories/keyboards-mice/',
        'headsets' => '/it-products/computer-accessories/headsets-audio/',
        'speakers' => '/it-products/computer-accessories/headsets-audio/',
        'microphones' => '/it-products/computer-accessories/headsets-audio/',
        'webcams' => '/smart-tech/conferencing-av/webcams/',
        'graphics_tablets' => '/it-products/computer-accessories/',
        'accessories' => null,
    ];

    /** Accessory slug prefix => taxonomy path (null = not stocked). */
    private const ACCESSORIES = [
        'media_pointer' => '/it-products/computer-accessories/',
        'g_stand' => '/it-products/computer-accessories/stands-mounts/',
        'uh_' => '/it-products/computer-accessories/hubs-docking-stations/',
        'acc_' => '/it-products/computer-accessories/cables-adapters/',
        'pd_' => '/it-products/computer-accessories/chargers-power-banks/',
    ];

    public function key(): string
    {
        return 'genius';
    }

    public function brandName(): string
    {
        return 'Genius';
    }

    public function minDelayMs(): int
    {
        return 5000;
    }

    public function imageHeaders(): array
    {
        return ['Referer' => self::BASE.'/'];
    }

    public function discover(BrandHttp $http): iterable
    {
        $seen = [];
        foreach (array_keys(self::LISTS) as $list) {
            for ($page = 1; $page <= 10; $page++) {
                $slugs = self::parseList($http->get(self::BASE."/en/products/list/{$list}?page={$page}"));
                // Out-of-range pages repeat the last page instead of 404ing.
                $new = array_diff($slugs, array_keys($seen));
                if ($new === []) {
                    break;
                }
                foreach ($new as $slug) {
                    $seen[$slug] = true;
                    if ($this->path($list, $slug) !== null) {
                        yield self::BASE."/en/products/view/{$slug}#{$list}";
                    }
                }
            }
        }
    }

    public function read(BrandHttp $http, string $reference): array
    {
        [$url, $list] = array_pad(explode('#', $reference, 2), 2, '');
        $slug = basename((string) parse_url($url, PHP_URL_PATH));
        $item = self::parseProduct($http->get($url));
        if ($item === null) {
            return [];
        }

        return [new ImportedProduct(
            name: trim("Genius {$item['model']} {$item['tagline']}"),
            mpn: $item['model'],
            categoryPath: $this->path($list, $slug),
            sourceCategory: $list !== '' ? $list : 'unknown',
            images: array_map(fn (string $src): string => self::BASE.$src, array_slice($item['images'], 0, 3)),
            specs: $item['specs'] + ($item['badges'] !== [] ? ['Features' => implode(', ', $item['badges'])] : []),
            summary: $item['features'] !== [] ? implode("\n", $item['features']) : null,
            sourceUrl: $url,
        )];
    }

    private function path(string $list, string $slug): ?string
    {
        if ($list !== 'accessories') {
            return self::LISTS[$list] ?? null;
        }
        foreach (self::ACCESSORIES as $prefix => $path) {
            if (str_starts_with($slug, $prefix)) {
                return $path;
            }
        }

        return null;
    }

    /** @return list<string> product slugs on a list page, in order */
    public static function parseList(string $html): array
    {
        preg_match_all('#<h4 class="prd-title"><a href="/en/products/view/([a-z0-9_]+)"#', $html, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @return array{model: string, tagline: string, badges: list<string>, features: list<string>, specs: array<string, string>, images: list<string>}|null
     */
    public static function parseProduct(string $html): ?array
    {
        $model = self::text(preg_match('#id="prdviewTitle">(.*?)</h2>#s', $html, $t) ? $t[1] : '');
        if ($model === '') {
            return null;
        }
        $tagline = self::text(preg_match('#id="prdviewTitle">.*?</h2>\s*<p class="prd-txt">(.*?)</p>#s', $html, $p) ? $p[1] : '');

        preg_match_all('#<div class="check_item"><span>(.*?)</span>#s', $html, $b);
        $badges = array_values(array_filter(array_map([self::class, 'text'], $b[1])));

        $features = [];
        if (preg_match('#feature-list-item">\s*<ul>(.*?)</ul>#s', $html, $f)) {
            preg_match_all('#<li[^>]*>(.*?)</li>#s', $f[1], $li);
            $features = array_values(array_filter(array_map([self::class, 'text'], $li[1])));
        }

        $specs = [];
        if (preg_match('#spec-item">(.*?)</div>#s', $html, $s)) {
            preg_match_all('#<h6>(.*?)</h6>\s*<ul>(.*?)</ul>#s', $s[1], $rows, PREG_SET_ORDER);
            foreach ($rows as [, $label, $list]) {
                $label = self::text($label);
                preg_match_all('#<li[^>]*>(.*?)</li>#s', $list, $li);
                // "Black / Grey<br /> *Product color and images shown ... reference ONLY"
                $values = array_map(fn (string $v): string => self::text((string) preg_replace('#<br\s*/?>\s*\*.*$#s', '', $v)), $li[1]);
                $value = implode('; ', array_filter($values, fn (string $v): bool => $v !== '' && ! preg_match('#^[-－—–]+$#u', $v)));
                if ($label !== '' && $value !== '') {
                    $specs[$label] ??= $value;
                }
            }
        }

        // First colour's gallery (the default shown on the page).
        $images = [];
        // Galleries are sibling <div class="prd_gallery"> blocks, one per colour.
        if (preg_match('#<div class="prd_gallery show">(.*?)(?:<div class="prd_gallery"|class="prdview_info")#s', $html, $g)) {
            preg_match_all('#f-carousel__slide"[^>]*data-src="(/upload/[^"]+)"#', $g[1], $im);
            $images = array_values(array_unique($im[1]));
        }

        return compact('model', 'tagline', 'badges', 'features', 'specs', 'images');
    }

    /** Text with entities decoded and control characters (seen in feature bullets) removed. */
    private static function text(string $html): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x{200E}\x{200F}]/u', '', BrandHttp::text($html)));
    }
}
