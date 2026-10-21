<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import runs — one row per bulk Icecat import launched from the admin.
 *
 * A run is the durable, screen-independent record of an import: who started it,
 * what it targeted, and live counters the admin polls for progress. The actual
 * work is a Laravel job batch (batch_id links the two); this table carries the
 * domain view — created vs skipped vs failed — that the generic batch metadata
 * does not. Because it is a database row, the person who launched the import can
 * close the tab and come back to watch it finish.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->create('import_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->string('source')->default('icecat');
            $table->string('label')->nullable();

            // What the run targets and how. Brands is a list of config keys (or
            // all of them); options carries since/catids/limit/max_images.
            $table->jsonb('brands')->default('[]');
            $table->jsonb('options')->default('{}');

            // preparing → running → completed | failed | cancelled
            $table->string('status')->default('preparing');

            // The Laravel job batch doing the work.
            $table->string('batch_id')->nullable();

            // Live counters, incremented atomically by the per-product jobs.
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);

            $table->unsignedBigInteger('started_by_user_id')->nullable();
            $table->text('message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('started_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('import_runs');
    }
};
