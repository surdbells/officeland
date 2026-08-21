<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Catches descriptions that differ only by the product name (SEO_SPEC §7.3).
 *
 * ── The rule this replaces ───────────────────────────────────────────────
 * §17.4 asked that no two products share more than 70% description similarity,
 * and that the import fail if they do. That rule is unworkable and SEO_SPEC
 * §7.3 corrects it: toner variants (CE320A / CE321A / CE322A) legitimately
 * share around 90% of their description, because they legitimately are the same
 * cartridge in four colours. A 70% global gate would reject the honest case and
 * block the import while doing it.
 *
 * The correction has three parts, and each one is load-bearing:
 *
 *   (a) **Measure only the prose.** The spec table is generated from structured
 *       attributes and is supposed to look alike across a family. Including it
 *       measures the template, not the writing.
 *
 *   (b) **Scope within a family.** Two office chairs and two toner cartridges
 *       being similar to each other is fine and expected. A chair reading like
 *       a cartridge is not a thing that happens. Comparing globally produces
 *       noise proportional to catalogue size.
 *
 *   (c) **Flag, never fail.** A false positive that blocks an import costs the
 *       whole import; a false positive that asks somebody to glance at a page
 *       costs a glance.
 *
 * ── Why the identifiers are stripped first ───────────────────────────────
 * The defect being caught is precisely "a boilerplate string with the name
 * swapped in" (§14.3). If the name, SKU and MPN stay in the text, they are the
 * one part guaranteed to differ, so they drag the similarity score DOWN — the
 * gate reads lowest exactly where the defect is worst. Removing them first
 * makes two boilerplate descriptions score ~1.0, which is what they are.
 */
final class SimilarityGate
{
    /**
     * Above this, two descriptions in one family are treated as the same text.
     *
     * Set high on purpose. Genuine variants differ by a colour, a page yield
     * or a capacity, and that difference is small in trigram terms — a lower
     * threshold would flag most of a legitimate family and teach reviewers to
     * approve without reading, which is worse than not flagging at all.
     */
    public const THRESHOLD = 0.95;

    /**
     * Compares one product against its family and records the result.
     *
     * Returns the nearest product if the pair is over the threshold, null
     * otherwise. Clearing the flag is deliberate: a rewritten description
     * should stop being flagged without anyone having to remember to unflag it.
     */
    public function check(Product $product): ?Product
    {
        $prose = $this->prose($product);

        if ($prose === '') {
            $this->clear($product);

            return null;
        }

        $nearest = null;
        $highest = 0.0;

        foreach ($this->family($product) as $candidate) {
            $score = $this->score($prose, $this->prose($candidate));

            if ($score > $highest) {
                $highest = $score;
                $nearest = $candidate;
            }
        }

        if ($nearest === null || $highest < self::THRESHOLD) {
            $this->clear($product);

            return null;
        }

        $product->forceFill([
            'description_similarity' => round($highest, 3),
            'description_similar_to_id' => $nearest->id,
            // Only stamped when it was not already flagged, so a re-run does
            // not silently invalidate an approval somebody already gave.
            'description_flagged_at' => $product->description_flagged_at ?? now(),
        ])->save();

        return $nearest;
    }

    /**
     * The product's family: same brand, same primary category.
     *
     * The closest thing to "family" the schema carries. It is the grouping
     * within which shared phrasing is legitimate — a manufacturer's range in
     * one product type — and outside which it is not.
     *
     * @return list<Product>
     */
    public function family(Product $product): array
    {
        if ($product->brand_id === null || $product->primary_category_id === null) {
            return [];
        }

        /** @var list<Product> $family */
        $family = Product::query()
            ->where('brand_id', $product->brand_id)
            ->where('primary_category_id', $product->primary_category_id)
            ->whereKeyNot($product->getKey())
            ->whereNotNull('description_html')
            ->limit(200)
            ->get()
            ->all();

        return $family;
    }

    /**
     * Trigram similarity, computed by Postgres rather than in PHP.
     *
     * pg_trgm is already enabled for typo-tolerant search, and `similarity()`
     * is the same measure used there — so a score here means the same thing it
     * means everywhere else in the system.
     */
    public function score(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        /** @var object{score: float|string}|null $row */
        $row = DB::selectOne('select similarity(?, ?) as score', [$left, $right]);

        return (float) ($row->score ?? 0.0);
    }

    /**
     * The comparable prose: description text with the identifiers removed.
     *
     * The spec table never reaches here — it is rendered from attributes at
     * display time and is not part of `description_html`.
     */
    public function prose(Product $product): string
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $product->description_html)) ?? ''));

        if ($text === '') {
            return '';
        }

        foreach ([$product->name, $product->sku, $product->mpn, $product->model_code, $product->brand_name_cached] as $identifier) {
            $identifier = mb_strtolower(trim((string) $identifier));

            if ($identifier !== '') {
                $text = str_replace($identifier, ' ', $text);
            }
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function clear(Product $product): void
    {
        if ($product->description_flagged_at === null && $product->description_similarity === null) {
            return;
        }

        $product->forceFill([
            'description_flagged_at' => null,
            'description_similarity' => null,
            'description_similar_to_id' => null,
        ])->save();
    }
}
