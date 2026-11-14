<?php

declare(strict_types=1);

namespace App\Importers\BrandSite;

/**
 * Reads one manufacturer's website into ImportedProducts.
 *
 * discover() lists references (URLs, handles, SKUs - whatever the adapter needs
 * to fetch one product); read() turns one reference into zero or more products
 * (a family page can carry several part numbers). Both go through BrandHttp so
 * every adapter shares the same User-Agent, retries and politeness delay.
 */
interface BrandSiteAdapter
{
    /** Command key, e.g. "casio". */
    public function key(): string;

    /** Brand name as it should appear on the storefront, e.g. "Casio". */
    public function brandName(): string;

    /** User-Agent for this site (BrandHttp::UA, the honest default, unless the site needs otherwise). */
    public function userAgent(): string;

    /** @return array<string, string>  extra headers for image downloads (e.g. a Referer the image host requires) */
    public function imageHeaders(): array;

    /** @return list<string>  PEM files of intermediates the site fails to send (see BrandHttp::withCertificates()) */
    public function extraCertificates(): array;

    /** Milliseconds between requests the site should see at minimum. */
    public function minDelayMs(): int;

    /** @return iterable<string> */
    public function discover(BrandHttp $http): iterable;

    /** @return list<ImportedProduct> */
    public function read(BrandHttp $http, string $reference): array;
}
