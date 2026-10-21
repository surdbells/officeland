<?php

declare(strict_types=1);

namespace App\Icecat;

use SimpleXMLElement;

/**
 * A parsed Icecat product-XML response.
 *
 * Reads the <ICECAT-interface><Product> document the XML interface returns and
 * exposes only the fields docs/ICECAT_MAPPING.md §3 maps into our schema. It is
 * deliberately tolerant: Icecat's XML has varied attribute spellings across
 * products and tiers, so each getter tries the documented attribute and a small
 * set of known alternates rather than assuming one shape.
 *
 * Nothing here publishes anything. Prose (title, descriptions) is returned for
 * storage as DRAFT only (D-015); the command decides what becomes a published
 * fact and what stays a source_* draft.
 */
final class IcecatDocument
{
    private function __construct(private readonly SimpleXMLElement $product) {}

    /**
     * Parse a response body, or null when it carries no product (an error code,
     * an empty interface, or malformed XML - all of which mean "nothing to
     * ingest" rather than a hard failure).
     */
    public static function fromXml(string $xml): ?self
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($root === false) {
            return null;
        }

        // The interface may carry an error (e.g. code 1 "product not found")
        // instead of a product.
        $product = $root->Product ?? null;

        if (! $product instanceof SimpleXMLElement || count($product) === 0 && $product->attributes() === null) {
            return null;
        }

        // A genuine product always has an ID; an error stub does not.
        if ((string) ($product['ID'] ?? '') === '' && (string) ($product['Prod_id'] ?? '') === '') {
            return null;
        }

        return new self($product);
    }

    public function icecatId(): ?int
    {
        $id = (string) ($this->product['ID'] ?? '');

        return $id !== '' && ctype_digit($id) ? (int) $id : null;
    }

    public function mpn(): ?string
    {
        return $this->cleanOrNull((string) ($this->product['Prod_id'] ?? ''));
    }

    public function brandName(): ?string
    {
        $supplier = $this->product->Supplier ?? null;

        return $supplier instanceof SimpleXMLElement
            ? $this->cleanOrNull((string) ($supplier['Name'] ?? ''))
            : null;
    }

    /**
     * The supplier/brand logo URL Icecat carries on the <Supplier> element, if
     * any. Icecat exposes it at a few attribute names depending on tier; the
     * high-res one is preferred.
     */
    public function supplierLogoUrl(): ?string
    {
        $supplier = $this->product->Supplier ?? null;

        if (! $supplier instanceof SimpleXMLElement) {
            return null;
        }

        foreach (['LogoHighPic', 'LogoPic', 'LogoMediumPic', 'LogoLowPic'] as $attr) {
            $url = trim((string) ($supplier[$attr] ?? ''));

            if ($url !== '' && str_starts_with($url, 'http')) {
                return $url;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function eans(): array
    {
        $eans = [];

        foreach ($this->product->EANCode ?? [] as $code) {
            $value = preg_replace('/\D/', '', (string) ($code['EAN'] ?? ''));

            if ($value !== null && $value !== '' && ! in_array($value, $eans, true)) {
                $eans[] = $value;
            }
        }

        return $eans;
    }

    public function title(): ?string
    {
        foreach ([
            (string) ($this->product['Title'] ?? ''),
            (string) ($this->product['Name'] ?? ''),
            (string) (($this->product->ProductDescription['Title'] ?? '')),
        ] as $candidate) {
            $clean = $this->cleanOrNull($candidate);

            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    public function shortDescription(): ?string
    {
        return $this->cleanOrNull(
            (string) ($this->product->ProductDescription['ShortDesc'] ?? '')
        ) ?? $this->cleanOrNull(
            (string) ($this->product->SummaryDescription->ShortSummaryDescription ?? '')
        );
    }

    public function longDescription(): ?string
    {
        return $this->cleanOrNull(
            (string) ($this->product->ProductDescription['LongDesc'] ?? '')
        ) ?? $this->cleanOrNull(
            (string) ($this->product->SummaryDescription->LongSummaryDescription ?? '')
        );
    }

    public function warranty(): ?string
    {
        return $this->cleanOrNull(
            (string) ($this->product->ProductDescription['WarrantyInfo'] ?? '')
        );
    }

    /**
     * Image URLs, largest first, deduplicated. HighPic (the full-resolution
     * master) leads so the command attaches it as the primary; the gallery
     * follows. Icecat warns its picture links are not permanent, so the command
     * mirrors every one into the content-addressed store (§3 Media).
     *
     * @return list<string>
     */
    public function imageUrls(): array
    {
        $urls = [];

        $add = function (string $url) use (&$urls): void {
            $url = trim($url);

            if ($url !== '' && str_starts_with($url, 'http') && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        };

        $add((string) ($this->product['HighPic'] ?? ''));

        foreach ($this->product->ProductGallery->ProductPicture ?? [] as $picture) {
            // Pic is the full-resolution gallery image. LowPic is a downscaled
            // duplicate of the same shot, so it is NOT collected - we mirror the
            // high-res originals only and derive our own sizes from them. Fall
            // back to LowPic solely when a picture has no Pic at all.
            $hiRes = (string) ($picture['Pic'] ?? '');
            $add($hiRes !== '' ? $hiRes : (string) ($picture['LowPic'] ?? ''));
        }

        return $urls;
    }

    /**
     * Specification groups with their features, ready to upsert into
     * attribute_groups / attributes / product_attribute_values.
     *
     * @return list<array{
     *   group_id: int|null,
     *   group_name: string|null,
     *   group_order: int,
     *   features: list<array{
     *     feature_id: int|null,
     *     name: string,
     *     value_raw: string,
     *     value_presentation: ?string,
     *     unit_symbol: ?string,
     *     order: int,
     *     searchable: bool
     *   }>
     * }>
     */
    public function featureGroups(): array
    {
        // Icecat's own group metadata: id -> name + display order.
        $groupMeta = [];

        foreach ($this->product->CategoryFeatureGroup ?? [] as $group) {
            $id = (string) ($group['ID'] ?? '');

            if ($id === '' || ! ctype_digit($id)) {
                continue;
            }

            $name = $this->cleanOrNull((string) ($group->FeatureGroup->Name['Value'] ?? ''))
                ?? $this->cleanOrNull((string) ($group->FeatureGroup->Name ?? ''));

            $groupMeta[(int) $id] = [
                'name' => $name,
                'order' => (int) ($group['No'] ?? 0),
            ];
        }

        // Bucket features by their group id, preserving the metadata order.
        $buckets = [];

        foreach ($this->product->ProductFeature ?? [] as $feature) {
            $valueRaw = $this->cleanOrNull((string) ($feature['Value'] ?? ''));

            if ($valueRaw === null) {
                continue;
            }

            $featureNode = $feature->Feature ?? null;
            $name = $featureNode instanceof SimpleXMLElement
                ? ($this->cleanOrNull((string) ($featureNode->Name['Value'] ?? ''))
                    ?? $this->cleanOrNull((string) ($featureNode->Name ?? '')))
                : null;

            if ($name === null) {
                continue;
            }

            $groupId = (string) ($feature['CategoryFeatureGroup_ID'] ?? '');
            $groupId = $groupId !== '' && ctype_digit($groupId) ? (int) $groupId : null;

            $featureId = $featureNode instanceof SimpleXMLElement ? (string) ($featureNode['ID'] ?? '') : '';
            $sign = $feature->Measure->Signs->Sign ?? null;

            $buckets[$groupId ?? 0][] = [
                'feature_id' => $featureId !== '' && ctype_digit($featureId) ? (int) $featureId : null,
                'name' => $name,
                'value_raw' => $valueRaw,
                'value_presentation' => $this->cleanOrNull((string) ($feature['Presentation_Value'] ?? '')),
                'unit_symbol' => $sign instanceof SimpleXMLElement ? $this->cleanOrNull((string) $sign) : null,
                'order' => (int) ($feature['No'] ?? 0),
                'searchable' => (string) ($feature['Searchable'] ?? '') === '1',
            ];
        }

        $groups = [];

        foreach ($buckets as $groupId => $features) {
            $meta = $groupMeta[$groupId] ?? ['name' => null, 'order' => 0];

            $groups[] = [
                'group_id' => $groupId === 0 ? null : $groupId,
                'group_name' => $meta['name'],
                'group_order' => $meta['order'],
                'features' => $features,
            ];
        }

        // Stable order: by the group's own display order, ungrouped last.
        usort($groups, static fn ($a, $b) => $a['group_order'] <=> $b['group_order']);

        return $groups;
    }

    /**
     * Typed relations to other products, by identifier. The command matches
     * each to our catalogue and links only those we stock; an unresolved type
     * is dropped rather than guessed.
     *
     * @return list<array{mpn: ?string, brand: ?string, type: ?string}>
     */
    public function relations(): array
    {
        $out = [];

        $related = $this->product->ProductRelated ?? null;

        if (! $related instanceof SimpleXMLElement) {
            return $out;
        }

        foreach ($related as $relation) {
            $type = $this->relationType((string) ($relation['categoryName'] ?? $relation['category'] ?? ''));

            $node = $relation->Product ?? $relation;
            $mpn = $this->cleanOrNull((string) ($node['Prod_id'] ?? ''));
            $brand = $node->Supplier ?? null;

            $out[] = [
                'mpn' => $mpn,
                'brand' => $brand instanceof SimpleXMLElement ? $this->cleanOrNull((string) ($brand['Name'] ?? '')) : null,
                'type' => $type,
            ];
        }

        return $out;
    }

    /** Map an Icecat relation category to our enum, or null when unknown. */
    private function relationType(string $raw): ?string
    {
        $raw = strtolower(trim($raw));

        return match (true) {
            $raw === '' => null,
            str_contains($raw, 'compatible') => 'compatible',
            str_contains($raw, 'alternative') => 'alternative',
            str_contains($raw, 'accessor') => 'accessory',
            str_contains($raw, 'consumable') || str_contains($raw, 'supply') => 'consumable',
            str_contains($raw, 'service') => 'service',
            default => null,
        };
    }

    private function cleanOrNull(string $value): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));

        return $value === '' ? null : $value;
    }
}
