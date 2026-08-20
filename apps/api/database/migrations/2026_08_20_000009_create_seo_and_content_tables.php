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
        // Redirects survive D-001's removal of migration: slug changes on
        // products and categories auto-insert here forever, and D-007 restores
        // the legacy mapping (5,063 product URLs + 161 categories).
        Schema::connection($this->connection)->create('redirects', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('from_path', 512)->unique();
            $table->string('to_path', 512)->nullable();
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->enum('source', ['migration', 'manual', 'auto_slug_change'])->default('manual');
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status_code');
        });

        DB::connection($this->connection)->statement(
            'ALTER TABLE redirects ADD CONSTRAINT redirects_status_valid CHECK (status_code IN (301, 302, 410))'
        );

        // A 301 without a destination is a broken redirect; a 410 with one is
        // a contradiction. Both are silent failures in production, so they are
        // rejected at write time.
        DB::connection($this->connection)->statement("
            ALTER TABLE redirects ADD CONSTRAINT redirects_target_coherent
            CHECK (
                (status_code = 410 AND to_path IS NULL) OR
                (status_code <> 410 AND nullif(trim(to_path), '') IS NOT NULL)
            )
        ");

        // Never redirect a path to itself — an infinite loop that a
        // hand-maintained CSV makes easy to introduce.
        DB::connection($this->connection)->statement(
            'ALTER TABLE redirects ADD CONSTRAINT redirects_no_self CHECK (to_path IS NULL OR from_path <> to_path)'
        );

        // Every 404 with its referrer, for redirect discovery
        // (MASTER BUILD PROMPT section 14.8).
        Schema::connection($this->connection)->create('not_found_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('path', 512);
            $table->text('referrer')->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedBigInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();

            $table->unique('path');
            $table->index('hits');
        });

        Schema::connection($this->connection)->create('seo_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('content_pages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('body_html')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->text('canonical_override')->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection($this->connection)->create('articles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->text('body_html')->nullable();
            $table->unsignedBigInteger('hero_media_id')->nullable();

            // E-E-A-T: Article schema requires a real named author with a real
            // bio page, and reviewed_by/reviewed_at carry the review signal
            // (MASTER BUILD PROMPT sections 14.4 and 14.7).
            $table->unsignedBigInteger('author_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('hero_media_id')->references('id')->on('media')->nullOnDelete();
            $table->index(['published_at', 'is_indexable']);
        });

        Schema::connection($this->connection)->create('locations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('address');
            $table->string('city');
            $table->string('country', 2)->default('NG');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('telephone', 32)->nullable();
            // LocalBusiness openingHoursSpecification (section 14.4).
            $table->jsonb('opening_hours')->default(DB::raw("'[]'::jsonb"));
            $table->text('body_html')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'locations', 'articles', 'content_pages',
            'seo_settings', 'not_found_log', 'redirects',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
    }
};
