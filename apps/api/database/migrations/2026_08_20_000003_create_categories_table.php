<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Category tree — materialized path, maximum depth 3.
 *
 * `path` holds '/office-furniture/seating/executive-chairs/'. Two requirements
 * from MASTER BUILD PROMPT section 7.1:
 *
 *   full breadcrumb in one query  -> ancestors via `:path LIKE path || '%'`
 *   whole subtree in one query    -> `path LIKE '/office-furniture/%'`
 *
 * Both are index-backed by the text_pattern_ops index below, which is what
 * makes a left-anchored LIKE use an index rather than scan.
 *
 * Depth is enforced by a CHECK constraint, not by application code. The legacy
 * taxonomy drifted to inconsistent depth precisely because nothing stopped it.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->create('categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('path')->unique();
            $table->unsignedTinyInteger('depth');

            // A category may not be marked indexable until it carries genuine
            // intro copy (150-300 words). An empty category page is thin
            // content, which is what we are replacing (SEO_SPEC.md section 7).
            $table->text('description_html')->nullable();
            $table->text('intro_copy')->nullable();

            $table->unsignedBigInteger('hero_media_id')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->text('canonical_override')->nullable();
            $table->boolean('is_indexable')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('product_count_cached')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
            $table->unique(['parent_id', 'slug']);
            $table->index(['parent_id', 'sort_order']);
            $table->index('depth');
        });

        $connection = DB::connection($this->connection);

        // Maximum depth 3 (docs/TAXONOMY.md section 1). Enforced in the
        // database so no import path, seeder or admin action can violate it.
        $connection->statement('
            ALTER TABLE categories
            ADD CONSTRAINT categories_depth_max_3 CHECK (depth BETWEEN 1 AND 3)
        ');

        // A materialized path must start and end with a slash, or the prefix
        // matching above produces false positives ("/office" matching
        // "/office-furniture").
        $connection->statement("
            ALTER TABLE categories
            ADD CONSTRAINT categories_path_format
            CHECK (path ~ '^/([a-z0-9]+(-[a-z0-9]+)*/){1,3}$')
        ");

        // text_pattern_ops makes left-anchored LIKE index-backed. Without it a
        // subtree query sequentially scans, which is a gate failure under
        // MASTER BUILD PROMPT section 7.4.
        $connection->statement('
            CREATE INDEX categories_path_pattern_idx
            ON categories (path text_pattern_ops)
        ');
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('categories');
    }
};
