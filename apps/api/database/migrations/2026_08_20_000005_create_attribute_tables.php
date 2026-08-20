<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed, filterable product attributes.
 *
 * Shape is driven by Icecat's real structure (docs/ICECAT_MAPPING.md):
 *
 *   CategoryFeatureGroup -> attribute_groups   (OVERVIEW, PERFORMANCE, SCREEN…)
 *   Feature              -> attributes
 *   ProductFeature       -> product_attribute_values
 *
 * The grouping is not cosmetic. Section 23.3 identified the grouped
 * specifications accordion as the highest-leverage fix for the legacy site's
 * unstructured supplier prose: the same facts, grouped, become filterable,
 * comparable and machine-readable, and feed `additionalProperty` in Product
 * JSON-LD (section 14.4).
 *
 * Facet indexability is a data attribute, never a hardcoded list
 * (section 14.5 rule 4).
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->create('attribute_groups', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('icecat_group_id')->nullable()->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('attributes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('group_id')->nullable();

            $table->string('code')->unique();
            $table->string('name');

            // 'string' values compare exactly; 'numeric' values populate
            // value_numeric and drive range facets; 'boolean' renders as
            // yes/no rather than "1".
            $table->enum('data_type', ['string', 'numeric', 'boolean', 'dimension'])->default('string');

            $table->string('unit', 32)->nullable();
            // Icecat Measure -> Signs, e.g. " for inches, ° for degrees.
            $table->string('unit_symbol', 16)->nullable();

            $table->unsignedInteger('icecat_feature_id')->nullable()->index();

            // Icecat ProductFeature@Searchable.
            $table->boolean('is_filterable')->default(false);
            // Whether a facet value on this attribute may become an indexable
            // landing page. Fewer than 30 to begin with (section 14.5 rule 4).
            $table->boolean('is_indexable_facet')->default(false);
            $table->boolean('is_key_spec')->default(false);

            // Icecat ProductFeature@No — buyer-orientation priority, higher is
            // more important. Drives spec ordering and key-spec bullets.
            $table->unsignedInteger('display_order')->default(0);

            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('attribute_groups')->nullOnDelete();
            $table->index(['is_filterable', 'display_order']);
            $table->index('is_indexable_facet');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('attributes');
        Schema::connection($this->connection)->dropIfExists('attribute_groups');
    }
};
