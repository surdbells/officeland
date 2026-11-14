<?php

declare(strict_types=1);

namespace App\Importers\BrandSite\Adapters;

use App\Importers\BrandSite\AbstractBrandAdapter;
use App\Importers\BrandSite\BrandHttp;
use App\Importers\BrandSite\ImportedProduct;

/**
 * APC (Schneider Electric) from the Schneider Nigeria catalogue, se.com/ng/en.
 *
 * apc.com only redirects here. The Nigeria catalogue already carries the
 * African/UK-plug variants (-MS, -MSX, -UK, I/IC), so it is the right region.
 *
 * Discovery: the NG product sitemaps list every Schneider product (~38k URLs);
 * APC lines are picked out by SKU prefix plus a slug keyword, after dropping
 * service/warranty/accessory SKUs and non-Nigerian plug suffixes. Each page is
 * then confirmed as APC from its JSON-LD brand before it is used.
 *
 * Fields: name, SKU, images (1500px renditions) and description from JSON-LD;
 * the full characteristic tables only from the SvelteKit hydration data, read
 * with regexes (it is a JS object literal, not JSON). Verified against BX950MI-MS,
 * SMT1500IC, SMV1500AI-MS, LE1200I, RBC7 and PME5B-MS.
 *
 * Akamai rejects a browser User-Agent from a non-browser client but accepts an
 * honest one, so this uses the engine's identifiable default.
 */
final class ApcAdapter extends AbstractBrandAdapter
{
    private const SITEMAP = 'https://www.se.com/ng/en/product/google-product-sitemapindex-NG-en.xml';

    private const UPS = '/smart-tech/power-backup/ups-systems/';

    private const AVR = '/smart-tech/power-backup/voltage-stabilisers/';

    private const SURGE = '/it-products/computer-accessories/cables-adapters/';

    private const BATTERY = '/smart-tech/power-backup/';

    /** [bucket, SKU regex, slug regex, taxonomy path] - first match wins. */
    private const BUCKETS = [
        ['battery', '#^(RBC|APCRBC|APCRBCV|BR\d+BP|SRT\d+R?M?BP|SRV\d+R?L?BP|SMX\d+R?M?BP|SURT\d+R?M?XLBP|SUA\d+R?M?XLBP|SMV\d+BP)#', '#battery#', self::BATTERY],
        ['ups', '#^(BX|BVX|BVS|BV|BE|BK|BR|BGM|SMT|SMTL|SMC|SMV|SMX|SRT|SRTL|SRV|SRVS|SRC|SURT|SUA|SPM)\d#', '#back-?ups|easy-ups|smart-?ups|apc-ups#', self::UPS],
        ['avr', '#^(LE|LS)\d#', '#line-?r|automatic-voltage-regulator#', self::AVR],
        ['surge', '#^(PM|PME|PMF|PMH|P\d)#', '#surgearrest#', self::SURGE],
    ];

    private const EXCLUDE_SLUG = '#refurbished|120v|100v|100-?120|service|extended-warranty|startup|start-up|hardwire|-kit|rail|software|license|management-card|bracket|bezel|bypass|transformer|maintenance|battery-pack|external-battery#';

    /** Plug suffixes for other markets; NG keeps none, I/IC, -MS, -MSX and -UK. */
    private const FOREIGN_PLUG = '#-(AR|AZ|GR|FR|IT|RS|CH|DK|SP|BR|IN|CN|TW|JP|KR|IS|LM|LA|PH|ID|VN|US|AU|NC|NCLI)$#';

    /** Spec tables that add noise rather than buying facts. */
    private const SKIP_TABLES = ['Graphs', 'Packing Units', 'Environment', 'Contractual warranty', 'Overview'];

    public function key(): string
    {
        return 'apc';
    }

    public function brandName(): string
    {
        return 'APC';
    }

    public function minDelayMs(): int
    {
        // Pages take 3-4 s to build server-side; one at a time, 2 s apart.
        return 2000;
    }

    public function discover(BrandHttp $http): iterable
    {
        foreach ($http->sitemapUrls(self::SITEMAP, '#/ng/en/product/[^/]+/[^/]+/?$#') as $url) {
            if (! preg_match('#/ng/en/product/([^/]+)/([^/]*)/?$#', $url, $m)) {
                continue;
            }
            [, $sku, $slug] = $m;
            $sku = strtoupper(urldecode($sku));
            $slug = strtolower($slug);

            if (preg_match(self::EXCLUDE_SLUG, $slug) || str_starts_with($sku, 'W')
                || preg_match('#^(SURT|SRT|SRTL|SUA|SMX|SMTL)0\d#', $sku) || str_ends_with($sku, 'USF')
                || preg_match(self::FOREIGN_PLUG, $sku)) {
                continue;
            }

            if ($this->bucket($sku, $slug) !== null) {
                yield $url;
            }
        }
    }

    public function read(BrandHttp $http, string $reference): array
    {
        $html = $http->get($reference);

        $product = BrandHttp::jsonLdProduct($html);
        if ($product === null || stripos((string) ($product['brand']['name'] ?? ''), 'APC') === false) {
            return [];
        }

        $sku = strtoupper(trim((string) ($product['sku'] ?? '')));
        $slug = preg_match('#/product/[^/]+/([^/]*)#', $reference, $m) ? strtolower($m[1]) : '';
        $bucket = $this->bucket($sku, $slug);

        [$rangeName] = [preg_match('#"rangeName":\s*"([^"]*)"#', $html, $r) ? $r[1] : ''];
        $specs = $this->specs($html);

        // Source quirk: several UPS pages label the input voltage window
        // ("140...300 V") as "Efficiency at full load".
        if (isset($specs['Efficiency at full load']) && preg_match('#d+s*...s*d+s*V#', $specs['Efficiency at full load'])) {
            $specs['Input voltage range'] = $specs['Efficiency at full load'];
            unset($specs['Efficiency at full load']);
        }

        $description = BrandHttp::text($product['description'] ?? '')
            ?: ($specs['Presentation'] ?? '');

        $images = array_values(array_filter(
            (array) ($product['image'] ?? []),
            fn ($u): bool => is_string($u) && ! str_contains($u, '_Living_Room_') && ! str_contains($u, '_Office_M_'),
        ));

        $name = html_entity_decode((string) ($product['name'] ?? $sku), ENT_QUOTES | ENT_HTML5);
        if ($name === strtolower($name)) {
            // A few catalogue names are all lower case ("replacement battery cartridge #7").
            $name = ucwords($name);
        }

        return [new ImportedProduct(
            name: $name,
            mpn: $sku !== '' ? $sku : null,
            categoryPath: $bucket[3] ?? null,
            sourceCategory: $rangeName !== '' ? $rangeName : ($bucket[0] ?? 'unknown'),
            images: $images,
            specs: array_filter($specs + ['Range' => $rangeName], fn ($v, $k): bool => $k !== 'Presentation' && $v !== '', ARRAY_FILTER_USE_BOTH),
            summary: $description !== '' ? $description : null,
            sourceUrl: $reference,
        )];
    }

    /** @return array{0:string,1:string,2:string,3:string}|null */
    private function bucket(string $sku, string $slug): ?array
    {
        foreach (self::BUCKETS as $b) {
            if (preg_match($b[1], $sku) && preg_match($b[2], $slug)) {
                return $b;
            }
        }

        return null;
    }

    /**
     * Characteristic tables from the hydration data, flattened to label => value.
     * Later tables never overwrite an earlier label (Main wins over Complementary).
     *
     * @return array<string, string>
     */
    private function specs(string $html): array
    {
        $start = strpos($html, 'specifications:{characteristicTables:[');
        if ($start === false) {
            return [];
        }
        $end = strpos($html, 'productCharacteristicsLabel:', $start) ?: $start + 200000;
        $segment = substr($html, $start, $end - $start);

        $str = '("(?:[^"\\\\]|\\\\.)*")';
        $out = [];

        foreach (array_slice(explode('{tableName:', $segment), 1) as $table) {
            if (! preg_match('#^'.$str.'#', $table, $t)) {
                continue;
            }
            if (in_array((string) json_decode($t[1]), self::SKIP_TABLES, true)) {
                continue;
            }

            preg_match_all('#characteristicName:'.$str.',characteristicValues:\[([\s\S]*?)\]\}#', $table, $rows, PREG_SET_ORDER);
            foreach ($rows as $row) {
                $label = trim((string) json_decode($row[1]));
                preg_match_all('#labelText:'.$str.'#', $row[2], $vals);
                $values = array_map(fn (string $v): string => trim(str_replace(['<br />', '<br/>', '<br>'], '; ', (string) json_decode($v))), $vals[1]);
                $value = trim(implode('; ', array_filter($values)), '; ');

                if ($label !== '' && $value !== '' && ! isset($out[$label])) {
                    $out[$label] = strip_tags($value);
                }
            }
        }

        return $out;
    }
}
