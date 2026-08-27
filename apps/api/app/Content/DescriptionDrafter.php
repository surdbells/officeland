<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Collection;

/**
 * Turns a product's structured attributes into a description DRAFT (§12b).
 *
 * ── Read this before assuming the output is publishable ──────────────────
 * It is not, and the system will not let it be. Everything this class writes
 * is stamped `description_generated_at`, and ContentMinimums refuses to let a
 * product be indexed while that stamp is newer than a human approval. That is
 * not caution for its own sake: §14.3 forbids *a boilerplate string with the
 * name swapped in*, and a template - however carefully written - is a
 * boilerplate string with the name swapped in. What makes the output
 * defensible is the editing pass afterwards, so the pipeline is built to make
 * that pass mandatory rather than encouraged.
 *
 * What the draft is genuinely for: getting an editor to 80% so that 15,000
 * products is a week of editing rather than a quarter of writing, and making
 * sure the facts in the copy come from the same place as the facts in the spec
 * table.
 *
 * ── Information density is the whole design ──────────────────────────────
 * SEO_SPEC §7.2: the line between defensible generated copy and spun
 * boilerplate is whether the output conveys each product's actual
 * DIFFERENTIATING specifications. So the draft is built from the attributes
 * that vary within the product's family, most-distinguishing first - not from
 * whatever attributes happen to be present. Two products in one family
 * therefore produce genuinely different drafts, or the drafter declines to
 * produce one at all.
 *
 * ── Why it refuses rather than padding ───────────────────────────────────
 * A product with three generic attributes cannot support 120 words of specific
 * prose. The alternative to refusing is padding with phrases that carry no
 * information - which is the exact failure mode, dressed as success. A refusal
 * puts the product in the review queue where a human can see it needs data
 * before it needs copy.
 */
final class DescriptionDrafter
{
    /**
     * Attributes that describe every product in a family identically are
     * noise. A draft built from them reads the same for every sibling, which
     * is the thing the similarity gate exists to catch.
     */
    private const MAX_ATTRIBUTES_IN_PROSE = 8;

    public function __construct(private readonly ContentMinimums $minimums) {}

    /**
     * Writes a draft onto the product, or returns null if it cannot.
     *
     * Never overwrites a human-approved description: the drafter is a
     * starting point, and running it across the catalogue a second time must
     * not silently discard a week of editing.
     */
    public function draft(Product $product): ?string
    {
        if ($this->hasHumanCopy($product)) {
            return null;
        }

        $body = $this->compose($product);

        if ($body === null) {
            return null;
        }

        $product->forceFill([
            'description_html' => $body,
            'description_generated_at' => now(),
        ])->save();

        return $body;
    }

    /**
     * Builds the draft text without saving it, for preview.
     */
    public function compose(Product $product): ?string
    {
        $product->loadMissing(['attributeValues.attribute', 'brand', 'primaryCategory']);

        $distinguishing = $this->distinguishingAttributes($product);

        if ($distinguishing->isEmpty()) {
            return null;
        }

        $paragraphs = array_values(array_filter([
            $this->opening($product, $distinguishing),
            $this->specifications($distinguishing),
            $this->logistics($product),
        ]));

        $html = implode("\n", array_map(
            static fn (string $paragraph): string => '<p>'.$paragraph.'</p>',
            $paragraphs,
        ));

        /*
         * Checked against the same floor the readiness rules use, so the
         * drafter cannot produce something that is guaranteed to fail review
         * for length. Under the floor, the honest answer is no draft.
         */
        return $this->minimums->words($html) >= ContentMinimums::PRODUCT_WORDS ? $html : null;
    }

    /**
     * The attributes that actually separate this product from its siblings.
     *
     * An attribute every sibling shares says nothing about THIS product. The
     * ones that vary are the reason a buyer would choose it, and they are what
     * a description has to be about.
     *
     * @return Collection<int, ProductAttributeValue>
     */
    public function distinguishingAttributes(Product $product): Collection
    {
        $shared = $this->familyCommonValues($product);

        return $product->attributeValues
            ->filter(function (ProductAttributeValue $value) use ($shared): bool {
                $attribute = $value->attribute;

                if (! $attribute instanceof Attribute || trim($value->displayValue()) === '') {
                    return false;
                }

                return ($shared[$attribute->id] ?? null) !== $value->displayValue();
            })
            ->sortBy(fn (ProductAttributeValue $value): int => $value->attribute->display_order ?? 999)
            ->take(self::MAX_ATTRIBUTES_IN_PROSE)
            ->values();
    }

    /**
     * Attribute values shared by every sibling in the family.
     *
     * @return array<int, string>
     */
    private function familyCommonValues(Product $product): array
    {
        if ($product->brand_id === null || $product->primary_category_id === null) {
            return [];
        }

        $siblings = Product::query()
            ->where('brand_id', $product->brand_id)
            ->where('primary_category_id', $product->primary_category_id)
            ->whereKeyNot($product->getKey())
            ->limit(50)
            ->pluck('id');

        if ($siblings->isEmpty()) {
            return [];
        }

        $values = ProductAttributeValue::query()
            ->whereIn('product_id', $siblings)
            ->with('attribute')
            ->get()
            ->groupBy('attribute_id');

        $common = [];

        foreach ($values as $attributeId => $group) {
            $distinct = $group->map(fn (ProductAttributeValue $v): string => $v->displayValue())->unique();

            // Shared by every sibling AND identical across all of them.
            if ($distinct->count() === 1 && $group->count() === $siblings->count()) {
                $common[(int) $attributeId] = (string) $distinct->first();
            }
        }

        return $common;
    }

    /** @param Collection<int, ProductAttributeValue> $distinguishing */
    private function opening(Product $product, Collection $distinguishing): string
    {
        $lead = $distinguishing->take(2)
            ->map(fn (ProductAttributeValue $v): string => mb_strtolower($this->attributeName($v)).' of '.$v->displayValue())
            ->implode(' and a ');

        $category = $product->primaryCategory?->name;
        $brand = $product->brand instanceof Brand ? $product->brand->name : $product->brand_name_cached;

        return e($product->name).' is a '.
            ($brand !== null ? e($brand).' ' : '').
            ($category !== null ? mb_strtolower(e(rtrim($category, 's'))) : 'product').
            ' with a '.e($lead).'. '.
            'The details below are drawn from the manufacturer specification and are what to check '.
            'against your own requirement before requesting a quote.';
    }

    /** @param Collection<int, ProductAttributeValue> $distinguishing */
    private function specifications(Collection $distinguishing): ?string
    {
        if ($distinguishing->count() < 2) {
            return null;
        }

        $sentences = $distinguishing
            ->map(fn (ProductAttributeValue $v): string => sprintf(
                '%s is %s',
                e($this->attributeName($v)),
                e($v->displayValue()),
            ))
            ->implode('. ');

        return $sentences.'. Each of these is listed again in the specification table, '.
            'so nothing here has to be taken on trust.';
    }

    private function logistics(Product $product): ?string
    {
        $parts = [];

        if (trim((string) $product->warranty_text) !== '') {
            $parts[] = 'Warranty: '.e(strip_tags((string) $product->warranty_text)).'.';
        }

        if ($product->lead_time_days !== null) {
            $parts[] = sprintf(
                'Typical lead time is %d working day%s from order.',
                $product->lead_time_days,
                $product->lead_time_days === 1 ? '' : 's',
            );
        }

        if (trim((string) $product->box_contents) !== '') {
            $parts[] = 'In the box: '.e(strip_tags((string) $product->box_contents)).'.';
        }

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts).' Ask us if you need a different configuration - '.
            'we quote against the specification you actually need rather than the one on the box.';
    }

    /**
     * The attribute's label, for a value that has already passed the filter in
     * distinguishingAttributes() and therefore has one.
     */
    private function attributeName(ProductAttributeValue $value): string
    {
        $attribute = $value->attribute;

        return $attribute instanceof Attribute ? $attribute->name : 'Specification';
    }

    /**
     * A description a person wrote or approved, which must never be clobbered.
     */
    private function hasHumanCopy(Product $product): bool
    {
        if (trim(strip_tags((string) $product->description_html)) === '') {
            return false;
        }

        // Never machine-written, so somebody typed it.
        if ($product->description_generated_at === null) {
            return true;
        }

        return $product->descriptionIsApproved();
    }
}
