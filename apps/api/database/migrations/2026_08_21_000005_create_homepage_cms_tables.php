<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homepage merchandising CMS.
 *
 * Everything editorial on the homepage used to be hardcoded in the Angular
 * component (home-page.ts): the hero copy, the "shop by category" list and the
 * "best sellers" band, which was really just the first page of the catalogue.
 * The component's own comments anticipated this ("swaps to a curated set the
 * day one exists"). These four tables are that source of truth, managed from
 * Filament.
 *
 * Every one is OPTIONAL by design. The storefront falls back to its current
 * derived behaviour when a table is empty, so shipping this with nothing
 * curated changes nothing a visitor sees - the admin opts in section by
 * section.
 *
 * `media_id` columns are plain unsigned integers, not foreign keys - the same
 * convention as categories.hero_media_id and brands.logo_media_id. Media rows
 * are content-addressed and soft-deletable, and a hard FK would fight that;
 * the application resolves the image and tolerates a missing one.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        // A site-wide banner above the header: a delivery notice, a showroom
        // event, a seasonal promo. The header shows the first active one whose
        // optional date window is open, lowest sort_order first.
        Schema::connection($this->connection)->create('announcements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->string('message');
            $table->string('url')->nullable();
            $table->string('link_label')->nullable();
            // info | promo | warning - drives the bar's colour on the storefront.
            $table->string('variant')->default('info');

            $table->boolean('is_active')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        // The homepage hero carousel. Each slide carries its own photograph and
        // its own copy, so the hero stops being one hardcoded headline laid
        // over rotating department photos.
        Schema::connection($this->connection)->create('hero_slides', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->string('headline');
            $table->string('subheadline')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->string('secondary_cta_label')->nullable();
            $table->string('secondary_cta_url')->nullable();

            $table->unsignedBigInteger('media_id')->nullable();

            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        // The "Shop by category" grid - a curated list of category tiles,
        // replacing the hardcoded MAJOR_CATEGORY_SOURCES. The count comes from
        // the category itself; the tile image is an optional override, falling
        // back to the category's own hero.
        Schema::connection($this->connection)->create('homepage_category_picks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('category_id');
            $table->string('label')->nullable();
            $table->unsignedBigInteger('media_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->index(['is_active', 'sort_order']);
        });

        // The "best sellers" band - a curated set of products, replacing the
        // first-page-of-the-catalogue stand-in.
        Schema::connection($this->connection)->create('homepage_product_picks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('product_id');

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique('product_id');
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('homepage_product_picks');
        Schema::connection($this->connection)->dropIfExists('homepage_category_picks');
        Schema::connection($this->connection)->dropIfExists('hero_slides');
        Schema::connection($this->connection)->dropIfExists('announcements');
    }
};
