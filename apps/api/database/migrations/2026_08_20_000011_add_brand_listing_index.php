<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Brand hub listing index.
 *
 * Found by the Phase 2 query-plan gate against 20,000 rows. The brand hub
 * query
 *
 *   WHERE brand_id = ? AND status = 'active' AND deleted_at IS NULL
 *   ORDER BY published_at DESC LIMIT 24
 *
 * was choosing `products_status_published_idx (status, published_at DESC)` and
 * filtering brand_id afterwards. That looks fine in EXPLAIN because LIMIT 24
 * short-circuits on a large brand, but for a brand holding a small share of the
 * catalogue the planner must walk a long way down the published_at ordering
 * before finding 24 matching rows — the cost estimate was 5933 against an
 * actual 0.26ms purely because the sampled brand was well represented.
 *
 * This index mirrors `products_active_idx` for categories: filter columns first,
 * sort column last, restricted to the rows the storefront can actually show.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE INDEX products_brand_active_idx
            ON products (brand_id, published_at DESC)
            WHERE status = 'active' AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP INDEX IF EXISTS products_brand_active_idx');
    }
};
