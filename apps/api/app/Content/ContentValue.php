<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Orders the review queue by what is worth writing first (§12f).
 *
 * ── The honest caveat, first ─────────────────────────────────────────────
 * §12f asks for a queue "ordered by keyword value". Keyword value means search
 * volume, and search volume comes from Search Console or a paid keyword tool.
 * **Neither exists yet**: Search Console has no data until the site is live and
 * crawled, and no keyword-tool subscription is in scope. Ordering by a number
 * we do not have would mean inventing one, and an invented priority order is
 * worse than an admitted proxy — people trust it.
 *
 * So this ranks by the best signals actually available before launch:
 *
 *   1. **Internal search demand.** Someone typed it into our own search box.
 *      This is real, first-party demand for a real product, and it is the
 *      single most reliable signal in the system — better than a keyword tool
 *      in one important way: it is *our* buyers, not the market at large.
 *   2. **Searches that returned nothing.** Weighted higher, not lower. A query
 *      with no results is demand we are visibly failing to meet, and a page
 *      that fixes it converts a dead end into a landing page.
 *   3. **Catalogue depth.** A category with 400 products earns more from an
 *      intro than one with 3, because the intro is what the whole subtree
 *      hangs off.
 *
 * **Replace this the moment GSC has 28 days of data.** The method is called
 * from one place so that swapping the signal is a change here and nowhere else.
 */
final class ContentValue
{
    /** A search that found nothing is worth more than one that found something. */
    private const ZERO_RESULT_WEIGHT = 3;

    /**
     * Demand scores for every category, keyed by id.
     *
     * Computed in one pass rather than per row: the review queue renders
     * hundreds of rows, and a per-row query here is the difference between a
     * page that loads and a page nobody opens.
     *
     * @return array<int, float>
     */
    public function forCategories(): array
    {
        $categories = Category::query()
            ->select(['id', 'name', 'product_count_cached'])
            ->get();

        $demand = $this->searchDemand();
        $scores = [];

        foreach ($categories as $category) {
            $name = mb_strtolower((string) $category->name);
            $matched = 0.0;

            foreach ($demand as $term => $weight) {
                /*
                 * Substring either way round: a category "Office Chairs"
                 * should be credited for the query "chairs", and a category
                 * "Chairs" for the query "office chairs". Crude, and correct
                 * often enough to order a work queue.
                 */
                if (str_contains($name, $term) || str_contains($term, $name)) {
                    $matched += $weight;
                }
            }

            // Depth counts, but cannot outrank real demand on its own.
            $scores[(int) $category->id] = $matched + sqrt((float) $category->product_count_cached);
        }

        return $scores;
    }

    /**
     * How valuable it is to finish this one product's description.
     *
     * A product inherits its category's demand, because nobody searches for a
     * specific SKU often enough for it to register — but a brand within a
     * category is a real query shape ("hp toner"), so a branded product in a
     * high-demand category ranks above an unbranded one.
     *
     * @param  array<int, float>  $categoryScores  from forCategories()
     */
    public function forProduct(Product $product, array $categoryScores): float
    {
        $inherited = $categoryScores[$product->primary_category_id ?? 0] ?? 0.0;

        return $inherited + ($product->brand_id !== null ? 1.0 : 0.0);
    }

    /**
     * Normalised query term => weight, from our own search log.
     *
     * @return array<string, float>
     */
    public function searchDemand(): array
    {
        /** @var list<object{query_normalised: string, occurrences: int|string, result_count: int|string}> $rows */
        $rows = DB::table('search_query_log')
            ->select(['query_normalised', 'occurrences', 'result_count'])
            ->orderByDesc('occurrences')
            ->limit(500)
            ->get()
            ->all();

        $demand = [];

        foreach ($rows as $row) {
            $term = mb_strtolower(trim($row->query_normalised));

            if ($term === '') {
                continue;
            }

            $weight = (float) $row->occurrences;

            if ((int) $row->result_count === 0) {
                $weight *= self::ZERO_RESULT_WEIGHT;
            }

            $demand[$term] = ($demand[$term] ?? 0.0) + $weight;
        }

        return $demand;
    }
}
