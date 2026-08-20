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
        // A product may appear in several categories; exactly one is
        // primary_category_id, and that one alone determines the canonical URL
        // and the breadcrumb (SEO_SPEC.md section 2).
        Schema::connection($this->connection)->create('product_category', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['product_id', 'category_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->index(['category_id', 'sort_order']);
        });

        Schema::connection($this->connection)->create('product_variants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('product_id');

            $table->string('sku')->nullable();
            $table->string('mpn')->nullable();
            $table->string('ean', 14)->nullable();
            $table->string('name');

            // Differing specifications, from Icecat VariantFeatures.
            $table->jsonb('attributes')->default(DB::raw("'{}'::jsonb"));

            // Unused under D-002; present so pricing can be enabled later.
            $table->decimal('price_ngn', 14, 2)->nullable();

            $table->enum('availability', [
                'in_stock', 'limited', 'made_to_order', 'lead_time', 'discontinued',
            ])->default('lead_time');

            $table->unsignedBigInteger('image_media_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('image_media_id')->references('id')->on('media')->nullOnDelete();
            $table->index(['product_id', 'sort_order']);
        });

        Schema::connection($this->connection)->create('product_attribute_values', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id');

            // Icecat is explicit that these serve different purposes:
            // Value for search and compare, Presentation_Value for display.
            // Storing one would force a choice between a spec table that reads
            // correctly and facets that filter correctly.
            // See docs/ICECAT_MAPPING.md finding F-1.
            $table->text('value_raw');
            $table->text('value_presentation')->nullable();

            // Extracted from value_raw for numeric attributes; drives range
            // facets (screen size, page yield, weight capacity).
            $table->decimal('value_numeric', 16, 4)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();

            // A product may legitimately hold several values for one attribute
            // (Icecat renders multi-value fields as bullets), so the unique key
            // includes the value.
            $table->unique(['product_id', 'attribute_id', 'value_raw'], 'product_attr_value_unique');
            $table->index(['attribute_id', 'value_raw']);
            $table->index(['attribute_id', 'value_numeric']);
        });

        Schema::connection($this->connection)->create('product_media', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('media_id');
            $table->enum('role', ['primary', 'gallery', 'spec_sheet', 'lifestyle'])->default('gallery');
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['product_id', 'media_id']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->index(['product_id', 'role', 'sort_order']);
        });

        // Typed relationships, supplied by Icecat ProductRelated rather than
        // hand-curated (docs/ICECAT_MAPPING.md finding F-3). This is what makes
        // the PDP "Complete your setup" rail and printer -> compatible toner
        // data-driven, and it is a primary internal-linking asset
        // (MASTER BUILD PROMPT section 14.7).
        Schema::connection($this->connection)->create('product_relations', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('related_product_id');
            $table->enum('relation_type', [
                'compatible', 'alternative', 'accessory', 'consumable', 'service',
            ]);
            $table->enum('source', ['icecat', 'manual'])->default('icecat');
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['product_id', 'related_product_id', 'relation_type'], 'product_relations_pk');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('related_product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['product_id', 'relation_type', 'sort_order'], 'product_relations_lookup_idx');

            // The reverse direction drives the prerender invalidation cascade:
            // when product A changes, every product whose rail displays A must
            // be regenerated (docs/ARCHITECTURE.md section 4.3).
            $table->index('related_product_id');
        });

        DB::connection($this->connection)->statement(
            'ALTER TABLE product_relations ADD CONSTRAINT product_relations_no_self
             CHECK (product_id <> related_product_id)'
        );
    }

    public function down(): void
    {
        foreach ([
            'product_relations',
            'product_media',
            'product_attribute_values',
            'product_variants',
            'product_category',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
    }
};
