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
        /*
         * Synonyms are essential for this catalogue and this market
         * (MASTER BUILD PROMPT section 16). Two distinct classes:
         *
         *   Nigerian-English usage — biro/pen, flash drive/pendrive
         *   Model-code equivalence — CE320A / 128A / HP 128A
         *
         * Stored in the database and admin-editable rather than compiled into
         * a PostgreSQL dictionary file, because merchandising staff must be
         * able to add a term without a deploy.
         */
        Schema::connection($this->connection)->create('search_synonyms', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('term');
            $table->string('synonym');
            $table->enum('type', ['usage', 'model_code', 'brand', 'typo'])->default('usage');
            // Bidirectional by default: a buyer searching either side should
            // find the same products. One-way is for typo corrections, where
            // the misspelling should find the correct term but not vice versa.
            $table->boolean('bidirectional')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['term', 'synonym']);
            $table->index(['term', 'is_active']);
        });

        // Zero-result queries are a merchandising and content asset, not a log
        // (section 14.10). Surfaced in Filament ordered by frequency.
        Schema::connection($this->connection)->create('search_queries_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('query_normalised');
            $table->string('query_raw');
            $table->unsignedInteger('result_count');
            $table->unsignedBigInteger('occurrences')->default(1);
            $table->unsignedBigInteger('clickthroughs')->default(0);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();

            $table->unique('query_normalised');
            // Partial index: the zero-result report is the hot query, and it
            // only ever reads rows where result_count = 0.
            $table->index('occurrences');
        });

        DB::connection($this->connection)->statement('
            CREATE INDEX search_queries_zero_result_idx
            ON search_queries_log (occurrences DESC)
            WHERE result_count = 0
        ');

        // Tracks what ingestion has seen, so incremental sync can compare
        // against Icecat index `Updated` timestamps rather than re-fetching
        // 15,000 products (docs/ICECAT_MAPPING.md section 2).
        Schema::connection($this->connection)->create('ingestion_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('source', 32)->default('icecat');
            $table->enum('status', ['running', 'completed', 'failed', 'cancelled'])->default('running');
            $table->unsignedInteger('products_seen')->default(0);
            $table->unsignedInteger('products_created')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_skipped')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->jsonb('summary')->default(DB::raw("'{}'::jsonb"));
            $table->uuid('trace_id')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();

            $table->index(['source', 'status', 'started_at']);
        });

        // Nothing publishes unreviewed (MASTER BUILD PROMPT section 17.4 in
        // spirit — the rewrite review queue survives even though migration
        // itself does not). Ordered by keyword value so the highest-impact
        // products get human attention first.
        Schema::connection($this->connection)->create('ingestion_review_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();

            $table->string('external_ref')->nullable();
            $table->enum('action', ['create', 'update', 'conflict', 'skip']);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            // Diff preview, so a reviewer sees exactly what would change
            // rather than approving blind.
            $table->jsonb('diff')->default(DB::raw("'{}'::jsonb"));
            $table->text('notes')->nullable();

            // Ranks the queue. Without GSC history (D-001 removed migration),
            // this comes from keyword research rather than existing traffic.
            $table->unsignedInteger('priority')->default(0);

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('run_id')->references('id')->on('ingestion_runs')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        foreach ([
            'ingestion_review_items', 'ingestion_runs',
            'search_queries_log', 'search_synonyms',
        ] as $table) {
            Schema::connection($this->connection)->dropIfExists($table);
        }
    }
};
