<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brands are a facet and a hub page type — never a segment of a category path.
 *
 * This is the single largest structural fix over the legacy taxonomy, where 35
 * of 161 categories were brand branches (`/product-category/hp/laptops-hp/`,
 * an 18-term `wiwu-accessories/*` tree). Brand hubs live at /brands/{slug}.
 * See docs/TAXONOMY.md section 5.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->create('brands', function (Blueprint $table): void {
            $table->bigIncrements('id');
            // Public identifier. Internal integer IDs are never exposed in API
            // payloads (MASTER BUILD PROMPT section 8.3).
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description_html')->nullable();

            // Icecat's supplier name, retained so ingestion can match reliably
            // even when our display name differs (e.g. "HP" vs "HP Inc.").
            $table->string('icecat_supplier_name')->nullable()->index();

            $table->unsignedBigInteger('logo_media_id')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_indexable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('product_count_cached')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_featured', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('brands');
    }
};
