<?php

declare(strict_types=1);

namespace App\Importers\BrandSite\Adapters;

use App\Importers\BrandSite\AbstractBrandAdapter;
use App\Importers\BrandSite\BrandHttp;
use App\Importers\BrandSite\ImportedProduct;
use RuntimeException;

/**
 * Apple from the US Apple Store (apple.com/shop). Only standard part numbers
 * (M...LL/A, M...AM/A): build-to-order configs ("Z...+...") cannot be sourced
 * through a distributor, so they are never listed.
 *
 *   Accessories  "Made by Apple" listing pages -> each product page's JSON-LD
 *                (name, part, EAN), Tech Specs panel and gallery.
 *   iPad         the buy page's product-selection bootstrap lists every part.
 *                Colours collapse into one product per model x size x storage
 *                x connectivity; the other colours' parts are kept as a spec.
 *   Mac          the buy page's preconfigured (standard) parts; each config
 *                page's JSON-LD name carries chip, memory and storage.
 *
 * Model-level specs come from apple.com/<family>/specs/: only rows that hold a
 * single value for every configuration (one full-width cell) are used, so a
 * column can never be attributed to the wrong part.
 */
final class AppleAdapter extends AbstractBrandAdapter
{
    private const BASE = 'https://www.apple.com';

    private const IMG = 'https://store.storeimages.cdn-apple.com/1/as-images.apple.com/is/';

    private const IMG_PARAMS = '?wid=2000&hei=2000&fmt=jpeg&qlt=90';

    /** Made-by-Apple listing facet => taxonomy path (null = decide by name). */
    private const FACETS = [
        'keyboards' => '/it-products/computer-accessories/keyboards-mice/',
        'mice' => '/it-products/computer-accessories/keyboards-mice/',
        'trackpads' => '/it-products/computer-accessories/keyboards-mice/',
        'chargers' => '/it-products/computer-accessories/chargers-power-banks/',
        'cables' => '/it-products/computer-accessories/cables-adapters/',
        'adapters' => null,
        'displays' => '/it-products/monitors-displays/monitors/',
        'headphones' => '/it-products/computer-accessories/headsets-audio/',
    ];

    /** Accessories that are not office/IT stock. */
    private const SKIP_NAME = '#watch|iphone|airtag|vision pro|polishing|herm[eè]s|gift card|magsafe battery|band\b|case\b|folio|solo loop#i';

    private const IPADS = [
        'ipad' => ['iPad', '/ipad-11/specs/'],
        'ipad-air' => ['iPad Air', '/ipad-air/specs/'],
        'ipad-pro' => ['iPad Pro', '/ipad-pro/specs/'],
        'ipad-mini' => ['iPad mini', '/ipad-mini/specs/'],
    ];

    private const MACS = [
        'macbook-air' => ['/it-products/computers/laptops/', '/macbook-air/specs/'],
        'macbook-pro' => ['/it-products/computers/laptops/', '/macbook-pro/specs/'],
        'macbook-neo' => ['/it-products/computers/laptops/', '/macbook-neo/specs/'],
        'imac' => ['/it-products/computers/desktops-all-in-ones/', '/imac/specs/'],
        'mac-mini' => ['/it-products/computers/desktops-all-in-ones/', '/mac-mini/specs/'],
        'mac-studio' => ['/it-products/computers/desktops-all-in-ones/', '/mac-studio/specs/'],
    ];

    /** Spec-page rows worth carrying (others are marketing, apps, accessibility). */
    private const SPEC_ROWS = '#^(chip|display|battery and power|power and battery|charging and expansion|external display support|display support|keyboard and trackpad|wireless|cellular and wireless|sim card|camera|front camera|audio|speakers|size and weight|operating system|in the box|sensors|connections|ports)$#i';

    /** @var array<string, array<string, mixed>> family => productSelectionData */
    private array $boot = [];

    /** @var array<string, array<string, array<string, string>>> url => panel => specs */
    private array $specPages = [];

    public function key(): string
    {
        return 'apple';
    }

    public function brandName(): string
    {
        return 'Apple';
    }

    public function minDelayMs(): int
    {
        return 2000;
    }

    public function discover(BrandHttp $http): iterable
    {
        $seen = [];
        foreach (array_keys(self::FACETS) as $facet) {
            $html = $http->get(self::BASE.'/shop/accessories/all/made-by-apple/'.$facet);
            foreach (self::jsonLd($html) as $ld) {
                $sku = (string) ($ld['offers'][0]['sku'] ?? '');
                // Studio Display tiles link to config pages: relative URL, no brand field.
                if (($ld['@type'] ?? '') !== 'Product' || ($ld['brand']['name'] ?? 'Apple') !== 'Apple' || $sku === '' || isset($seen[$sku])
                    || preg_match(self::SKIP_NAME, (string) ($ld['name'] ?? ''))) {
                    continue;
                }
                $seen[$sku] = true;
                $url = (string) preg_replace('#\?.*$#', '', (string) $ld['url']);

                yield 'acc|'.$facet.'|'.(str_starts_with($url, '/') ? self::BASE.$url : $url);
            }
        }

        foreach (array_keys(self::IPADS) as $family) {
            foreach (array_keys($this->ipadGroups($http, $family)) as $group) {
                yield 'ipad|'.$family.'|'.$group;
            }
        }

        foreach (array_keys(self::MACS) as $family) {
            foreach (array_keys($this->macGroups($http, $family)) as $group) {
                yield 'mac|'.$family.'|'.$group;
            }
        }
    }

    public function read(BrandHttp $http, string $reference): array
    {
        // --url may pass a plain product URL; treat it as an accessory.
        if (str_starts_with($reference, 'http')) {
            $reference = 'acc||'.$reference;
        }
        [$kind, $family, $key] = explode('|', $reference, 3);

        return match ($kind) {
            'acc' => $this->accessory($http, $family, $key),
            'ipad' => $this->ipad($http, $family, $key),
            'mac' => $this->mac($http, $family, $key),
            default => [],
        };
    }

    /** @return list<ImportedProduct> */
    private function accessory(BrandHttp $http, string $facet, string $url): array
    {
        $html = $http->get($url);
        $lds = self::jsonLd($html);
        $product = null;
        $gallery = [];
        foreach ($lds as $ld) {
            if (($ld['@type'] ?? '') === 'Product') {
                $product ??= $ld;
            }
            if (($ld['@type'] ?? '') === 'ImageGallery') {
                $gallery = array_column((array) ($ld['associatedMedia'] ?? []), 'contentUrl');
            }
        }
        $sku = strtoupper((string) ($product['offers'][0]['sku'] ?? ''));
        if ($product === null || ($product['brand']['name'] ?? 'Apple') !== 'Apple' || $sku === '' || str_starts_with($sku, 'Z')) {
            return [];
        }
        $name = self::clean((string) $product['name']);

        $path = self::FACETS[$facet] ?? null;
        if ($path === null) {
            $path = preg_match('#\d+W\b.*(power adapter|charger)|magsafe charger#i', $name)
                ? '/it-products/computer-accessories/chargers-power-banks/'
                : '/it-products/computer-accessories/cables-adapters/';
        }
        if (preg_match(self::SKIP_NAME, $name)) {
            return [];
        }

        $specs = $this->panelSpecs($html, 'TechSpecs-panel');
        if (($box = $this->panelList($html, 'WhatsInTheBox-panel')) !== '') {
            $specs['In the Box'] = $box;
        }
        if (($req = $this->panelList($html, 'SystemRequirements-panel')) !== '') {
            $specs['System Requirements'] = $req;
        }
        if (! empty($product['color'])) {
            $specs['Colour'] = self::clean((string) $product['color']);
        }
        // Displays are config pages without a Tech Specs panel: use the model's specs page.
        if (preg_match('#/buy-mac/(studio-display(?:-xdr)?)/#', $url, $d)) {
            $specs += ['Configuration' => trim((string) preg_replace('#^Studio Display(?: XDR)? - #', '', $name))]
                + $this->specPage($http, '/'.$d[1].'/specs/', '');
        }

        // Other colours of the same item (not keyboard languages: too many).
        if (preg_match('#productVariationsPart":"((?:[^"\\\\]|\\\\.)*)"#', $html, $v)) {
            $variations = json_decode((string) json_decode('"'.$v[1].'"'), true)['productVariations'] ?? [];
            if (count($variations) > 1 && count($variations) <= 4) {
                $specs['Part numbers'] = implode('; ', array_map(
                    fn ($pn, $t): string => $pn.' ('.self::clean((string) ($t['productTitle'] ?? '')).')',
                    array_keys($variations),
                    $variations,
                ));
            }
        }

        $ean = preg_match('#UPC or EAN No\.:\s*(\d{12,13})#', $html, $e) ? $e[1] : null;

        // Base-part packshots first (white ground), then the gallery angles.
        $base = substr($sku, 0, 5);
        $images = [self::IMG.$base.self::IMG_PARAMS];
        foreach ($gallery as $g) {
            $images[] = (string) preg_replace('#\?.*$#', self::IMG_PARAMS, (string) $g);
        }

        $overview = $this->panelList($html, 'Overview-panel', ' ');
        $meta = preg_match('#<meta name="description" content="([^"]*)"#', $html, $m) ? html_entity_decode($m[1]) : '';

        return [new ImportedProduct(
            name: 'Apple '.$name,
            mpn: $sku,
            categoryPath: $path,
            sourceCategory: 'accessories/'.$facet,
            images: array_slice(array_values(array_unique($images)), 0, 3),
            specs: $specs,
            summary: $overview !== '' ? $overview : (trim(preg_replace('#(^|(?<=\.))\s*[^.]*(financing|Buy now).*$#is', '', $meta) ?? '') ?: null),
            sourceUrl: preg_replace('#\?.*$#', '', $url),
            ean: $ean,
        )];
    }

    /**
     * iPad parts grouped by size x storage x connectivity (colours collapse).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function ipadGroups(BrandHttp $http, string $family): array
    {
        $data = $this->bootstrap($http, '/shop/buy-ipad/'.$family, $family);
        $groups = [];
        $seen = [];
        foreach ((array) ($data['products'] ?? []) as $p) {
            $part = (string) ($p['partNumber'] ?? '');
            // The bootstrap repeats a part once per carrier option.
            if (! preg_match('#^M[A-Z0-9]{4}LL/A$#', $part) || isset($seen[$part])) {
                continue;
            }
            $seen[$part] = true;
            $key = implode('-', array_filter([
                $p['familyType'] ?? '', $p['dimensionScreensize'] ?? '', $p['dimensionCapacity'] ?? '', $p['dimensionConnection'] ?? '',
            ]));
            $groups[$key][] = $p;
        }

        return $groups;
    }

    /** @return list<ImportedProduct> */
    private function ipad(BrandHttp $http, string $family, string $group): array
    {
        $parts = $this->ipadGroups($http, $family)[$group] ?? [];
        if ($parts === []) {
            return [];
        }
        $display = (array) ($this->boot[$family]['displayValues'] ?? []);
        $first = $parts[0];
        [$model, $specUrl] = self::IPADS[$family];

        $size = isset($first['dimensionScreensize']) ? str_replace('inch', '-inch', (string) $first['dimensionScreensize']) : '';
        $storage = strtoupper((string) ($first['dimensionCapacity'] ?? ''));
        $cellular = str_contains((string) ($first['dimensionConnection'] ?? ''), 'cell');
        $connection = $cellular ? 'Wi-Fi + Cellular' : 'Wi-Fi';
        $chip = preg_match('#_(m\d+)_#i', (string) ($first['familyType'] ?? ''), $c) ? strtoupper($c[1]) : null;

        $colour = fn (array $p): string => (string) ($display['dimensionColor'][$p['dimensionColor'] ?? '']['value'] ?? ($p['dimensionColor'] ?? ''));

        $specs = array_filter([
            'Storage' => $storage,
            'Screen Size' => $size,
            'Connectivity' => $connection,
            'Colours' => implode(', ', array_map($colour, $parts)),
            'Part numbers' => count($parts) > 1
                ? implode('; ', array_map(fn ($p): string => $p['partNumber'].' ('.$colour($p).')', $parts))
                : null,
        ]) + $this->specPage($http, $specUrl, $size);

        return [new ImportedProduct(
            name: trim("Apple {$model} {$size}".($chip !== null ? " ({$chip})" : '')." {$connection} {$storage}"),
            mpn: (string) $first['partNumber'],
            categoryPath: '/it-products/computers/tablets/',
            sourceCategory: 'ipad/'.$family,
            images: ! empty($first['imageKey']) ? [self::IMG.$first['imageKey'].self::IMG_PARAMS] : [],
            specs: $specs,
            summary: null,
            sourceUrl: self::BASE.'/shop/product/'.$first['partNumber'],
        )];
    }

    /**
     * Mac standard parts grouped by size x chip config (colours collapse).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function macGroups(BrandHttp $http, string $family): array
    {
        $data = $this->bootstrap($http, '/shop/buy-mac/'.$family, $family);
        $groups = [];
        foreach ((array) ($data['products'] ?? []) as $p) {
            $part = (string) ($p['btrOrFdPartNumber'] ?? '');
            if (! preg_match('#^M[A-Z0-9]{4}LL/A$#', $part)) {
                continue;
            }
            $dims = (array) ($p['dimensions'] ?? []);
            unset($dims['chassis-dimensionColor']);
            ksort($dims);
            $groups[implode('-', $dims) ?: $part][] = $p;
        }

        return $groups;
    }

    /** @return list<ImportedProduct> */
    private function mac(BrandHttp $http, string $family, string $group): array
    {
        $parts = $this->macGroups($http, $family)[$group] ?? [];
        if ($parts === []) {
            return [];
        }
        [$path, $specUrl] = self::MACS[$family];
        $part = (string) $parts[0]['btrOrFdPartNumber'];

        // /shop/product/<part> redirects to the config page.
        $html = $http->get(self::BASE.'/shop/product/'.$part);
        $ld = null;
        foreach (self::jsonLd($html) as $block) {
            if (($block['@type'] ?? '') === 'Product') {
                $ld = $block;
                break;
            }
        }
        if ($ld === null || strtoupper((string) ($ld['offers'][0]['sku'] ?? '')) !== $part) {
            return [];
        }

        // "MacBook Air, 13-inch, M5 Chip, 10-core CPU, 10-core GPU, Midnight, 16GB memory, 1TB storage"
        $full = self::clean((string) $ld['name']);
        $segments = array_map('trim', explode(',', $full));
        $model = $segments[0];
        $size = preg_match('#(\d+(?:\.\d+)?)-inch#', $full, $m) ? $m[1].'-inch' : '';
        $chip = preg_match('#(M\d+(?: Pro| Max| Ultra)?) Chip#i', $full, $m) ? $m[1] : '';
        $cpu = preg_match('#(\d+)-core CPU#', $full, $m) ? $m[1] : '';
        $gpu = preg_match('#(\d+)-core GPU#', $full, $m) ? $m[1] : '';
        $memory = preg_match('#(\d+(?:GB|TB)) (?:unified )?memory#i', $full, $m) ? $m[1] : '';
        $storage = preg_match('#(\d+(?:GB|TB)) (?:SSD )?storage#i', $full, $m) ? $m[1] : '';

        $colours = [];
        foreach ($parts as $p) {
            $key = (string) ($p['dimensions']['chassis-dimensionColor'] ?? '');
            $colours[(string) $p['btrOrFdPartNumber']] = (string) ($this->boot[$family]['mainDisplayValues']['chassis-dimensionColor'][$key]['header'] ?? $key);
        }

        $specs = array_filter([
            'Chip' => trim("Apple {$chip}".($cpu !== '' ? ", {$cpu}-core CPU" : '').($gpu !== '' ? ", {$gpu}-core GPU" : '')),
            'Memory' => $memory !== '' ? "{$memory} unified memory" : null,
            'Storage' => $storage !== '' ? "{$storage} SSD" : null,
            'Screen Size' => $size,
            'Colours' => implode(', ', array_filter($colours)),
            'Part numbers' => count($parts) > 1
                ? implode('; ', array_map(fn ($pn, $c): string => "{$pn} ({$c})", array_keys($colours), $colours))
                : null,
        ]) + $this->specPage($http, $specUrl, $size);

        $swatch = (string) ($this->boot[$family]['mainDisplayValues']['chassis-dimensionColor'][$parts[0]['dimensions']['chassis-dimensionColor'] ?? '']['image']['imageName'] ?? '');
        $image = $swatch !== '' ? self::IMG.str_replace('_SW_COLOR', '', $swatch).self::IMG_PARAMS : null;

        // "Buy the 15-inch MacBook Air laptop in Starlight with ... storage. Get ..." -> first sentence,
        // without the store voice or the one colour (the product covers all of them).
        $first = preg_match('#^(.*?\.)(\s|$)#s', self::clean((string) ($ld['description'] ?? '')), $s) ? $s[1] : '';
        $description = trim(preg_replace(['#^Buy the #', '# in [A-Z][\w ]*? with #'], ['The ', ' with '], $first) ?? '');

        return [new ImportedProduct(
            name: trim(preg_replace('/\s+/', ' ', "Apple {$model} {$size} {$chip} ({$cpu}-core CPU, {$gpu}-core GPU) {$memory} {$storage}") ?? ''),
            mpn: $part,
            categoryPath: $path,
            sourceCategory: 'mac/'.$family,
            images: $image !== null ? [$image] : [],
            specs: $specs,
            summary: $description !== '' ? $description : null,
            sourceUrl: self::BASE.'/shop/product/'.$part,
        )];
    }

    /** @return array<string, mixed> */
    private function bootstrap(BrandHttp $http, string $path, string $family): array
    {
        if (! isset($this->boot[$family])) {
            try {
                $this->boot[$family] = self::jsonObjectAfter($http->get(self::BASE.$path), 'productSelectionData:') ?? [];
            } catch (RuntimeException) {
                $this->boot[$family] = [];
            }
        }

        return $this->boot[$family];
    }

    /**
     * Shared spec rows from a model's specs page, for the panel matching the
     * screen size when the page has one per size.
     *
     * @return array<string, string>
     */
    private function specPage(BrandHttp $http, string $path, string $size): array
    {
        if (! isset($this->specPages[$path])) {
            try {
                $html = $http->get(self::BASE.$path);
            } catch (RuntimeException) {
                $html = '';
            }
            $this->specPages[$path] = self::parseSpecPage($html);
        }
        $panels = $this->specPages[$path];
        foreach ($panels as $label => $rows) {
            if ($size !== '' && str_contains($label, $size)) {
                return $rows;
            }
        }

        return reset($panels) ?: [];
    }

    /** @return array<string, array<string, string>> panel => label => value */
    public static function parseSpecPage(string $html): array
    {
        preg_match_all('#aria-labelledby="([^"]+)" aria-hidden#', $html, $p, PREG_OFFSET_CAPTURE);
        $panels = $p[1] !== [] ? $p[1] : [['all', 0]];
        $out = [];
        foreach ($panels as $i => [$label, $offset]) {
            $end = $panels[$i + 1][1] ?? strlen($html);
            $rows = array_slice(explode('class="techspecs-row"', substr($html, $offset, $end - $offset)), 1);
            foreach ($rows as $row) {
                $name = BrandHttp::text(preg_match('#techspecs-rowheader"[^>]*>(.*?)</div>#s', $row, $h) ? str_replace('&shy;', '', $h[1]) : '');
                if (! preg_match(self::SPEC_ROWS, $name) || substr_count($row, 'class="techspecs-column') !== 1) {
                    continue;
                }
                $at = (int) strpos($row, 'class="techspecs-column');
                $cell = substr($row, (int) strpos($row, '>', $at) + 1);
                $cell = preg_replace(['#<sup.*?</sup>#s', '#<(picture|figure|noscript|script)\b.*?</\1>#s', '#<img[^>]*>#'], '', $cell) ?? $cell;
                $cell = preg_replace('#<(br|/p|/li|/h\d)[^>]*>#i', '|', $cell) ?? $cell;
                $items = [];
                foreach (explode('|', strip_tags($cell)) as $t) {
                    $t = trim(self::clean(BrandHttp::text($t)), '; ');
                    // Diagram callouts repeat earlier items run together; drop them.
                    $seen = implode(' ', $items);
                    if ($t === '' || mb_strlen($t) >= 200 || ($items !== [] && array_diff(explode(' ', $t), explode(' ', str_replace(';', '', $seen))) === [])) {
                        continue;
                    }
                    $items[] = $t;
                }
                if ($items !== []) {
                    // Operating system rows open with the OS name, then marketing.
                    $out[$label][$name] = preg_match('#^operating system$#i', $name) ? $items[0] : implode('; ', array_slice($items, 0, 8));
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_values(array_filter(array_map(fn (string $j) => json_decode($j, true), $m[1]), 'is_array'));
    }

    /**
     * The {...} JSON object following a marker in inline script, brace-matched.
     *
     * @return array<string, mixed>|null
     */
    private static function jsonObjectAfter(string $html, string $marker): ?array
    {
        $at = strpos($html, $marker);
        $start = $at !== false ? strpos($html, '{', $at) : false;
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $len = strlen($html);
        for ($i = $start; $i < $len; $i++) {
            $c = $html[$i];
            if ($inString) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                $decoded = json_decode(substr($html, $start, $i - $start + 1), true);

                return is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }

    /**
     * "Label: Value" paragraphs of a PDP panel.
     *
     * @return array<string, string>
     */
    private function panelSpecs(string $html, string $panel): array
    {
        $specs = [];
        foreach (explode('|', $this->panelList($html, $panel, '|')) as $line) {
            if (str_contains($line, ': ')) {
                [$label, $value] = array_map('trim', explode(': ', $line, 2));
                $value = rtrim($value, '*');
                if ($label !== '' && $value !== '' && mb_strlen($label) < 60) {
                    $specs[$label] ??= $value;
                }
            }
        }

        return $specs;
    }

    private function panelList(string $html, string $panel, string $glue = '; '): string
    {
        $at = strpos($html, $panel.' row"');
        if ($at === false) {
            return '';
        }
        $segment = substr($html, $at, 20000);
        $segment = substr($segment, 0, strpos($segment, 'rc-pdsection-panel', 30) ?: strlen($segment));
        preg_match_all('#<p[^>]*>(.*?)</p>#s', $segment, $m);

        return implode($glue, array_filter(array_map(fn (string $p): string => self::clean(BrandHttp::text($p)), $m[1])));
    }

    /** Apple's non-breaking hyphens and spaces, normalised. */
    private static function clean(string $text): string
    {
        return trim(str_replace(["\u{2011}", "\u{2010}", "\u{00A0}", "\u{2009}"], ['-', '-', ' ', ' '], $text));
    }
}
