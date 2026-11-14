<?php

declare(strict_types=1);

namespace App\Importers\BrandSite;

/**
 * One product as a brand site describes it, normalised by its adapter.
 *
 * Adapters only extract; every catalogue decision (matching an existing product,
 * whether to overwrite, which images to keep) belongs to the engine, so seven
 * brands behave identically once their data is read.
 */
final class ImportedProduct
{
    /**
     * @param  list<string>  $images  largest available renditions, primary first
     * @param  array<string, scalar|null>  $specs  label => value
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $mpn,
        /** Our taxonomy path, or null when the adapter could not place it (reported as unmapped). */
        public readonly ?string $categoryPath,
        /** The brand site's own category/series label, for the unmapped report. */
        public readonly string $sourceCategory,
        public readonly array $images = [],
        public readonly array $specs = [],
        /** Vendor marketing copy: stored as source_description, never published verbatim (D-015). */
        public readonly ?string $summary = null,
        public readonly ?string $sourceUrl = null,
        public readonly ?string $ean = null,
    ) {}
}
