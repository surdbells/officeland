<?php

declare(strict_types=1);

namespace App\Icecat;

use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use XMLReader;

/**
 * Streams Icecat's repository index (files.index.xml) one <file> entry at a
 * time, filtered to the suppliers we care about.
 *
 * The index lists every data-sheet Icecat holds - ~3.9 GB uncompressed - so it
 * is NEVER loaded into memory. XMLReader walks it as a cursor; only the entries
 * for a wanted supplier are expanded into a small DOM to read their GTINs. The
 * caller points it at the gzipped file through the compress.zlib:// wrapper, so
 * the 310 MB download is decompressed on the fly without a temp copy.
 *
 * Each yielded entry carries what auto-discovery needs to create a product:
 * the Icecat id to fetch the full data-sheet by, the MPN, the category id to
 * map, a GTIN, and the model name for logging.
 */
final class IcecatIndex
{
    /**
     * Ensure the gzipped repository index is cached locally, downloading it if
     * missing, stale, or forced. Returns the local path, or null on failure.
     *
     * Shared by every entry point that scans the index (the CLI dispatcher and
     * the admin prepare job) so the ~310 MB download and its TTL live in one
     * place.
     */
    public static function ensureCached(bool $refresh = false): ?string
    {
        $path = (string) config('icecat.index_cache');
        $ttl = (int) config('icecat.index_ttl_hours', 24) * 3600;
        $fresh = is_file($path) && (time() - (int) filemtime($path)) < $ttl;

        if ($fresh && ! $refresh) {
            return $path;
        }

        @mkdir(dirname($path), 0775, true);

        try {
            $response = Http::withBasicAuth((string) config('icecat.username'), (string) config('icecat.password'))
                ->timeout(1800)
                ->sink($path)
                ->get((string) config('icecat.endpoints.index'));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! is_file($path) || (filesize($path) ?: 0) < 1_000_000) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    /**
     * @param  string  $uri  an XMLReader-openable URI; for the gzip index use
     *                       "compress.zlib:///path/to/files.index.xml.gz"
     * @param  list<int>  $supplierIds  only entries for these suppliers are yielded
     * @param  bool  $onMarketOnly  skip entries flagged off-market
     * @param  bool  $fullOnly  skip Limited="Yes" entries (not fetchable at this tier)
     * @return Generator<int, array{
     *   product_id: int, mpn: ?string, supplier_id: int, catid: ?int,
     *   model_name: ?string, ean: ?string, date_added: ?int
     * }>
     */
    public static function stream(
        string $uri,
        array $supplierIds,
        bool $onMarketOnly = true,
        bool $fullOnly = true,
    ): Generator {
        $wanted = array_flip($supplierIds);

        $reader = new XMLReader;

        // libxml must be allowed to resolve the gzip stream; the index also
        // declares a DTD we neither need nor want to fetch.
        if (@$reader->open($uri, null, LIBXML_NONET) === false) {
            throw new RuntimeException("Could not open the Icecat index at {$uri}.");
        }

        try {
            // Advance to the first <file> element.
            while ($reader->read() && $reader->localName !== 'file') {
                // walking to the first entry
            }

            while ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'file') {
                $supplierId = (int) $reader->getAttribute('Supplier_id');

                // Cheap attribute checks before expanding the node to a DOM.
                $skip = ! isset($wanted[$supplierId])
                    || ($onMarketOnly && $reader->getAttribute('On_Market') !== '1')
                    || ($fullOnly && strtolower((string) $reader->getAttribute('Limited')) === 'yes');

                if (! $skip) {
                    $productId = (int) $reader->getAttribute('Product_ID');

                    if ($productId > 0) {
                        yield [
                            'product_id' => $productId,
                            'mpn' => self::clean($reader->getAttribute('Prod_ID')),
                            'supplier_id' => $supplierId,
                            'catid' => ($c = $reader->getAttribute('Catid')) !== null && $c !== '' ? (int) $c : null,
                            'model_name' => self::clean($reader->getAttribute('Model_Name')),
                            // EAN is read from the fetched data-sheet, not here: the
                            // index carries it only in child <EAN_UPC> nodes, and
                            // expanding every entry to a DOM to read them made
                            // XMLReader accumulate the parsed tree and exhaust memory
                            // over a large brand. Attributes alone keep the scan at
                            // constant memory.
                            'ean' => null,
                            // When Icecat first catalogued the product, as YYYYMMDD -
                            // a workable proxy for product age / recency.
                            'date_added' => self::ymd($reader->getAttribute('Date_Added')),
                        ];
                    }
                }

                // Move to the next <file> sibling, skipping this entry's children
                // (EAN_UPCS, Country_Markets) without reading them one by one.
                if (! $reader->next('file')) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** "20051027000000" → 20051027 (int), or null. */
    private static function ymd(?string $value): ?int
    {
        $value = (string) $value;

        return strlen($value) >= 8 && ctype_digit(substr($value, 0, 8))
            ? (int) substr($value, 0, 8)
            : null;
    }
}
