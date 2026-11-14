<?php

declare(strict_types=1);

namespace App\Importers\BrandSite;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The one HTTP client every brand adapter uses.
 *
 * Polite by construction: an honest, identifiable User-Agent, a minimum gap between requests that the adapter sets and
 * the operator can only raise, and bounded retries on transient failures. It
 * also carries the parsing helpers more than one adapter needs - sitemaps,
 * schema.org JSON-LD and Shopify's paged product feed - so each adapter is only
 * the part that is genuinely different about its site.
 */
final class BrandHttp
{
    /** Honest and identifiable: some brand CDNs reject a browser UA that does not match its TLS fingerprint. */
    public const UA = 'OfficeLandCatalogue/1.0 (+https://officelandng.com)';

    private float $lastRequestAt = 0.0;

    public int $requests = 0;

    public function __construct(private readonly int $delayMs, private readonly string $userAgent = self::UA) {}

    public function get(string $url, array $headers = []): string
    {
        $this->pace();

        $response = Http::timeout(45)
            ->connectTimeout(15)
            ->withHeaders($headers + ['User-Agent' => $this->userAgent, 'Accept-Language' => 'en'])
            ->retry(2, 2000, throw: false)
            ->get($url);

        $this->requests++;

        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} for {$url}");
        }

        return $response->body();
    }

    /** @return array<mixed> */
    public function json(string $url, array $headers = []): array
    {
        $data = json_decode($this->get($url, $headers + ['Accept' => 'application/json']), true);

        if (! is_array($data)) {
            throw new RuntimeException("Not JSON: {$url}");
        }

        return $data;
    }

    /**
     * Every <loc> in a sitemap, following sitemap indexes, filtered by a regex.
     *
     * @return list<string>
     */
    public function sitemapUrls(string $sitemap, string $pattern = '#.#', int $depth = 0): array
    {
        $xml = $this->get($sitemap);
        preg_match_all('#<loc>\s*(.*?)\s*</loc>#s', $xml, $m);
        $locs = array_map(fn (string $u): string => html_entity_decode(trim($u)), $m[1]);

        // A sitemap index lists further sitemaps; recurse (bounded) into them.
        if (str_contains($xml, '<sitemapindex') && $depth < 2) {
            $urls = [];
            foreach ($locs as $child) {
                try {
                    array_push($urls, ...$this->sitemapUrls($child, $pattern, $depth + 1));
                } catch (RuntimeException) {
                    continue;
                }
            }

            return array_values(array_unique($urls));
        }

        return array_values(array_unique(array_filter($locs, fn (string $u): bool => preg_match($pattern, $u) === 1)));
    }

    /**
     * The first schema.org Product node in a page's JSON-LD (looks inside
     * @graph and arrays), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function jsonLdProduct(string $html): ?array
    {
        preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $m);

        foreach ($m[1] as $block) {
            $data = json_decode(html_entity_decode(trim($block)), true);
            if (! is_array($data)) {
                continue;
            }

            $nodes = isset($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
            foreach ($nodes as $node) {
                $type = (array) ($node['@type'] ?? []);
                if (in_array('Product', $type, true) || in_array('ProductGroup', $type, true)) {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * Every product of a Shopify store (or one collection), paged.
     *
     * @return iterable<array<string, mixed>>
     */
    public function shopifyProducts(string $base, ?string $collection = null): iterable
    {
        $path = $collection !== null ? "/collections/{$collection}/products.json" : '/products.json';

        for ($page = 1; $page <= 60; $page++) {
            $batch = $this->json(rtrim($base, '/').$path."?limit=250&page={$page}")['products'] ?? [];
            if ($batch === []) {
                return;
            }
            yield from $batch;
        }
    }

    /** Plain text from an HTML fragment, whitespace collapsed. */
    public static function text(?string $html): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/h\d)[^>]*>#i', ' ', (string) $html) ?? ''), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function pace(): void
    {
        $elapsed = (microtime(true) - $this->lastRequestAt) * 1000;
        if ($this->lastRequestAt > 0 && $elapsed < $this->delayMs) {
            usleep((int) (($this->delayMs - $elapsed) * 1000));
        }
        $this->lastRequestAt = microtime(true);
    }
}
