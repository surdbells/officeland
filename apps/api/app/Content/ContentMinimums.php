<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;

/**
 * The content floor from SEO_SPEC §7, in one place (§12a, §12b).
 *
 * ── Why this is enforced and not merely reported ─────────────────────────
 * The Phase 12 gate is "no indexable page below the content minimums". A
 * checkbox with a helpful hint next to it does not achieve that: it takes one
 * bulk edit, one CSV column or one impatient afternoon to put 15,000 thin
 * pages into the sitemap, and the damage - a domain assessed as thin at the
 * moment it first gets crawled - is slow to undo and invisible while it
 * happens. Same reasoning as alt text in Phase 4f, which is enforced on the
 * model rather than in the form because the importer does not use the form.
 *
 * ── Why the rules live here rather than on the models ────────────────────
 * They are one policy that applies across four record types, they will change
 * as the content strategy is tested against reality, and the review queue, the
 * readiness report and the model guard all have to agree about them. Three
 * copies of "at least 120 words" is three chances to disagree.
 *
 * ── What is deliberately NOT checked ─────────────────────────────────────
 * Quality. Nothing here can tell whether 120 words are worth reading. These
 * are floors - necessary, nowhere near sufficient - and the similarity gate
 * (SimilarityGate) plus human approval carry the part a word count cannot.
 */
final class ContentMinimums
{
    /** §14.7: 120+ words of original description per product. */
    public const PRODUCT_WORDS = 120;

    /** SEO_SPEC §7: 150–300 words of genuine buying context per category. */
    public const CATEGORY_WORDS_MIN = 150;

    /**
     * The upper bound is advisory, not a bar to indexing.
     *
     * A 400-word category intro is not a reason to withhold a page from
     * Google - it is a reason to tell the editor it reads long. Enforcing it
     * would be enforcing a house style as though it were an SEO requirement.
     */
    public const CATEGORY_WORDS_ADVISORY_MAX = 300;

    /** A spec table needs enough rows to be a table (SEO_SPEC §7). */
    public const PRODUCT_MIN_ATTRIBUTES = 3;

    public function for(Model $record): Readiness
    {
        return match (true) {
            $record instanceof Product => $this->product($record),
            $record instanceof Category => $this->category($record),
            default => Readiness::ready(),
        };
    }

    /**
     * §14.7 product minimums: original prose, a spec table built from
     * structured attributes, an "in the box" list, warranty and lead time.
     */
    public function product(Product $product): Readiness
    {
        $failures = [];

        $words = $this->words($product->description_html);

        if ($words < self::PRODUCT_WORDS) {
            $failures[] = sprintf('description needs %d more words', self::PRODUCT_WORDS - $words);
        }

        /*
         * D-015: syndicated prose is a draft, never the published text.
         * Publishing Icecat's own words is the legacy site's defect §4B/C at
         * three times the scale, on a domain with no authority to spend.
         */
        if ($this->isVerbatimSource($product)) {
            $failures[] = 'description is the supplier text verbatim - rewrite it';
        }

        /*
         * A machine wrote it and nobody has read it since. This is the check
         * that separates 15,000 defensible pages from 15,000 spun ones.
         */
        if ($this->awaitingApproval($product)) {
            $failures[] = 'generated description not yet approved by a person';
        }

        if ($this->awaitingSimilarityReview($product)) {
            $failures[] = sprintf(
                'reads %d%% the same as another product in its family - approve or rewrite',
                (int) round((float) $product->description_similarity * 100),
            );
        }

        $attributes = $product->relationLoaded('attributeValues')
            ? $product->attributeValues->count()
            : $product->attributeValues()->count();

        if ($attributes < self::PRODUCT_MIN_ATTRIBUTES) {
            $failures[] = sprintf('needs %d more specifications', self::PRODUCT_MIN_ATTRIBUTES - $attributes);
        }

        if ($this->blank($product->box_contents)) {
            $failures[] = 'no "in the box" list';
        }

        if ($this->blank($product->warranty_text)) {
            $failures[] = 'no warranty stated';
        }

        /*
         * "How soon can we have it" is the single most commercially useful
         * fact this page can carry - D-002 removed prices and there are no
         * stock counts, so without it the page answers nothing a buyer is
         * actually deciding on.
         *
         * An in-stock product is exempt because "in stock" IS the answer.
         * Demanding a number there would be demanding a field, not a fact,
         * and a required field with no meaning gets filled with a lie.
         */
        if ($product->lead_time_days === null && $product->availability !== 'in_stock') {
            $failures[] = 'no lead time, and not marked in stock';
        }

        return new Readiness($failures);
    }

    public function category(Category $category): Readiness
    {
        $words = $this->words($category->intro_copy);

        if ($words < self::CATEGORY_WORDS_MIN) {
            return new Readiness([
                sprintf('intro needs %d more words', self::CATEGORY_WORDS_MIN - $words),
            ]);
        }

        return Readiness::ready();
    }

    /** Advisory only - never a reason to withhold a page. */
    public function categoryReadsLong(Category $category): bool
    {
        return $this->words($category->intro_copy) > self::CATEGORY_WORDS_ADVISORY_MAX;
    }

    public function words(?string $html): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $html)) ?? '');

        if ($text === '') {
            return 0;
        }

        /*
         * Counted by whitespace rather than with str_word_count, which is
         * locale-bound and drops anything non-ASCII - including the model
         * codes and dimensions that make up a real specification sentence.
         */
        return count(preg_split('/\s+/u', $text) ?: []);
    }

    private function blank(?string $value): bool
    {
        return trim(strip_tags((string) $value)) === '';
    }

    /**
     * Whether the published description is still the supplier's own text.
     *
     * Normalised before comparison so that a copy-paste through the editor -
     * which changes the markup but not a word of the prose - is still caught.
     */
    private function isVerbatimSource(Product $product): bool
    {
        $source = $this->normalise($product->source_description);

        if ($source === '') {
            return false;
        }

        return $this->normalise($product->description_html) === $source;
    }

    private function awaitingApproval(Product $product): bool
    {
        if ($product->description_generated_at === null) {
            return false;
        }

        return $product->description_approved_at === null
            || $product->description_approved_at->lessThan($product->description_generated_at);
    }

    private function awaitingSimilarityReview(Product $product): bool
    {
        if ($product->description_flagged_at === null) {
            return false;
        }

        return $product->description_approved_at === null
            || $product->description_approved_at->lessThan($product->description_flagged_at);
    }

    private function normalise(?string $html): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $html)) ?? ''));
    }
}
