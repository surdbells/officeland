<?php

declare(strict_types=1);

namespace App\Icecat;

use Generator;
use RuntimeException;
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
     * @param  string  $uri  an XMLReader-openable URI; for the gzip index use
     *                       "compress.zlib:///path/to/files.index.xml.gz"
     * @param  list<int>  $supplierIds  only entries for these suppliers are yielded
     * @param  bool  $onMarketOnly  skip entries flagged off-market
     * @param  bool  $fullOnly  skip Limited="Yes" entries (not fetchable at this tier)
     * @return Generator<int, array{
     *   product_id: int, mpn: ?string, supplier_id: int, catid: ?int,
     *   model_name: ?string, ean: ?string
     * }>
     */
    public static function stream(
        string $uri,
        array $supplierIds,
        bool $onMarketOnly = true,
        bool $fullOnly = true,
    ): Generator {
        $wanted = array_flip($supplierIds);

        $reader = new XMLReader();

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
                            'ean' => self::firstEan($reader),
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

    /**
     * The preferred GTIN of the current <file>: an approved GTIN-13 if present,
     * else the first approved, else the first of any. Only called for a wanted
     * entry, so expanding it to a DOM is cheap.
     */
    private static function firstEan(XMLReader $reader): ?string
    {
        $node = $reader->expand();

        if (! $node instanceof \DOMNode) {
            return null;
        }

        $codes = (new \DOMDocument())->importNode($node, true);
        $eans = [];

        foreach ($codes->getElementsByTagName('EAN_UPC') as $ean) {
            /** @var \DOMElement $ean */
            $value = preg_replace('/\D/', '', (string) $ean->getAttribute('Value'));

            if ($value === null || $value === '') {
                continue;
            }

            $approved = $ean->getAttribute('IsApproved') === '1';
            $gtin13 = $ean->getAttribute('Format') === 'GTIN-13';

            if ($approved && $gtin13) {
                return $value; // best match, stop early
            }

            $eans[] = ['value' => $value, 'approved' => $approved];
        }

        foreach ($eans as $e) {
            if ($e['approved']) {
                return $e['value'];
            }
        }

        return $eans[0]['value'] ?? null;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
