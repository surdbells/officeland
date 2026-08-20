<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->create('products', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            // ---------------------------------------------------------------
            // Identity
            //
            // MASTER BUILD PROMPT section 7.1 specified `sku` unique NOT NULL
            // as the migration deduplication key. The legacy audit found ~60%
            // of products carry no SKU at all, and D-001 removed migration
            // entirely, so SKU is no longer a matching key.
            //
            // Matching is now MPN + brand -> GTIN/EAN -> Icecat ID
            // (docs/ICECAT_MAPPING.md). SKU is nullable and becomes the stable
            // business identity once assigned.
            // ---------------------------------------------------------------
            $table->string('sku')->nullable()->unique();
            $table->string('mpn')->nullable();
            $table->string('model_code')->nullable();
            $table->string('ean', 14)->nullable();
            $table->unsignedBigInteger('icecat_id')->nullable()->unique();

            $table->string('name');
            $table->string('slug')->unique();

            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('primary_category_id')->nullable();

            // Denormalised so the generated search_vector can weight it — a
            // generated column may only reference columns in its own row.
            $table->string('brand_name_cached')->nullable();

            // ---------------------------------------------------------------
            // Copy. Source fields are ingested but never published (D-015).
            // Every Icecat subscriber receives identical prose; publishing it
            // verbatim recreates legacy defect section 4B/C at scale.
            // ---------------------------------------------------------------
            $table->text('short_description')->nullable();
            $table->text('description_html')->nullable();
            $table->text('spec_summary')->nullable();
            $table->text('warranty_text')->nullable();
            $table->text('source_title')->nullable();
            $table->text('source_description')->nullable();

            // Flattened specification text, maintained on save, weighted C in
            // the search vector.
            $table->text('search_text_specs')->nullable();

            // ---------------------------------------------------------------
            // Commercial. D-002: no public pricing. These columns are created
            // nullable and unused so posture C can be enabled later without a
            // migration, exactly as section 5.3 instructs.
            // ---------------------------------------------------------------
            $table->enum('pricing_mode', ['public', 'quote_only'])->default('quote_only');
            $table->decimal('price_ngn', 14, 2)->nullable();
            $table->decimal('compare_at_price_ngn', 14, 2)->nullable();
            $table->date('price_valid_until')->nullable();

            $table->enum('availability', [
                'in_stock', 'limited', 'made_to_order', 'lead_time', 'discontinued',
            ])->default('lead_time');
            $table->unsignedInteger('lead_time_days')->nullable();

            // B2B pack sizes.
            $table->unsignedInteger('min_order_qty')->default(1);
            $table->unsignedInteger('qty_step')->default(1);

            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');

            // ---------------------------------------------------------------
            // SEO. Defaults to NOT indexable: a product below the content
            // minimum of section 14.7 must not reach the index by accident.
            // Publishing raises this explicitly once the content gate passes.
            // ---------------------------------------------------------------
            $table->boolean('is_indexable')->default(false);
            $table->text('canonical_override')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();

            // Provenance, so coverage gaps are queryable rather than invisible
            // (Baseus and other non-sponsoring brands arrive via csv/manual).
            $table->enum('source', ['icecat', 'csv', 'manual'])->default('manual');
            $table->timestamp('icecat_synced_at')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
            $table->foreign('primary_category_id')->references('id')->on('categories')->nullOnDelete();
        });

        $connection = DB::connection($this->connection);

        // ---------------------------------------------------------------
        // Weighted full-text search vector (section 7.1).
        //   A name  B brand/mpn/model/sku  C specs  D description
        //
        // Generated and STORED so it is always consistent with the row and
        // costs nothing at query time. unaccent() is not used here: it is not
        // IMMUTABLE, and PostgreSQL rejects non-immutable functions in a
        // generated column. Accent folding is applied at query time instead.
        // ---------------------------------------------------------------
        $connection->statement("
            ALTER TABLE products ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                setweight(to_tsvector('english',
                    coalesce(brand_name_cached, '') || ' ' ||
                    coalesce(mpn, '') || ' ' ||
                    coalesce(model_code, '') || ' ' ||
                    coalesce(sku, '')
                ), 'B') ||
                setweight(to_tsvector('english', coalesce(search_text_specs, '')), 'C') ||
                setweight(to_tsvector('english', coalesce(short_description, '')), 'D')
            ) STORED
        ");

        $connection->statement('CREATE INDEX products_search_vector_idx ON products USING GIN (search_vector)');

        // Trigram indexes for typo tolerance and partial model-code lookup.
        // "CE320A" typed as "ce302a" still has to find the toner.
        $connection->statement('CREATE INDEX products_name_trgm_idx ON products USING GIN (name gin_trgm_ops)');
        $connection->statement('CREATE INDEX products_sku_trgm_idx ON products USING GIN (sku gin_trgm_ops)');
        $connection->statement('CREATE INDEX products_mpn_trgm_idx ON products USING GIN (mpn gin_trgm_ops)');

        // Hot-path composites (section 7.4).
        $connection->statement('CREATE INDEX products_status_published_idx ON products (status, published_at DESC)');
        $connection->statement('CREATE INDEX products_primary_category_status_idx ON products (primary_category_id, status)');
        $connection->statement('CREATE INDEX products_brand_status_idx ON products (brand_id, status)');

        // Partial indexes: almost every storefront query filters status =
        // 'active' AND deleted_at IS NULL, so indexing only those rows keeps
        // the index small and hot.
        $connection->statement("
            CREATE INDEX products_active_idx ON products (primary_category_id, published_at DESC)
            WHERE status = 'active' AND deleted_at IS NULL
        ");
        $connection->statement("
            CREATE INDEX products_indexable_idx ON products (is_indexable)
            WHERE status = 'active' AND deleted_at IS NULL
        ");

        // Matching key for ingestion: MPN is only unique within a brand.
        $connection->statement('CREATE UNIQUE INDEX products_brand_mpn_unique ON products (brand_id, mpn) WHERE mpn IS NOT NULL AND deleted_at IS NULL');

        // B2B quantity rules must be coherent or the quote basket cannot
        // enforce them.
        $connection->statement('ALTER TABLE products ADD CONSTRAINT products_qty_positive CHECK (min_order_qty >= 1 AND qty_step >= 1)');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('products');
    }
};
