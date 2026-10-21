<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute the cached product counts on brands and categories.
 *
 * `product_count_cached` is what the storefront shows on the megamenu, the
 * category pages and the brand hubs - counting ACTIVE, non-deleted products.
 * It is a cache, and until now only the legacy WooCommerce importer refreshed
 * it (categories only), so brands always read zero and any Icecat import left
 * both stale. This rebuilds both from the live data in two set-based updates,
 * fast enough to run after every import and on a schedule.
 *
 * Categories count their whole SUBTREE (a department includes its descendants'
 * products); brands count their own active products.
 */
final class RecountCatalogue extends Command
{
    protected $signature = 'catalogue:recount';

    protected $description = 'Rebuild cached product counts on brands and categories';

    public function handle(): int
    {
        $this->info('Recounting categories (subtree, active products)…');
        DB::statement(<<<'SQL'
            UPDATE categories AS c
            SET product_count_cached = COALESCE(counted.total, 0)
            FROM (
                SELECT ancestors.id, COUNT(DISTINCT pc.product_id) AS total
                FROM categories AS ancestors
                LEFT JOIN categories AS descendants
                    ON descendants.path LIKE ancestors.path || '%'
                LEFT JOIN product_category AS pc ON pc.category_id = descendants.id
                LEFT JOIN products AS p
                    ON p.id = pc.product_id AND p.status = 'active' AND p.deleted_at IS NULL
                GROUP BY ancestors.id
            ) AS counted
            WHERE c.id = counted.id
        SQL);

        $this->info('Recounting brands (active products)…');
        // Reset first so a brand whose last active product was removed drops to
        // zero; the join below only touches brands that still have one.
        DB::table('brands')->update(['product_count_cached' => 0]);
        DB::statement(<<<'SQL'
            UPDATE brands AS b
            SET product_count_cached = counted.total
            FROM (
                SELECT brand_id, COUNT(*) AS total
                FROM products
                WHERE status = 'active' AND deleted_at IS NULL AND brand_id IS NOT NULL
                GROUP BY brand_id
            ) AS counted
            WHERE b.id = counted.brand_id
        SQL);

        $this->info('Done. The storefront navigation cache is dropped on its next build; clear it now with cache:forget if needed.');

        return self::SUCCESS;
    }
}
