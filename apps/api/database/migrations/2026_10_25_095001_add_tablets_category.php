<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A Tablets category under IT Products > Computers.
 *
 * The 13-brand Icecat survey surfaced 700+ tablets (Lenovo Tab, Idea Tab, …)
 * with no home in the taxonomy. They are portable computers but not laptops, so
 * a dedicated leaf gives them a real, indexable category page and keeps the
 * laptop listings honest. config('icecat.category_map')[897] points here.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        $computers = DB::connection($this->connection)->table('categories')
            ->where('path', '/it-products/computers/')
            ->first();

        if ($computers === null) {
            return;
        }

        DB::connection($this->connection)->table('categories')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'parent_id' => $computers->id,
            'name' => 'Tablets',
            'slug' => 'tablets',
            'path' => '/it-products/computers/tablets/',
            'depth' => $computers->depth + 1,
            'sort_order' => 90,
            'is_indexable' => false,
            'product_count_cached' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection($this->connection)->table('categories')
            ->where('path', '/it-products/computers/tablets/')
            ->delete();
    }
};
