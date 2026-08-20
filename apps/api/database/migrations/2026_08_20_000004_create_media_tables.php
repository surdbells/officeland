<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content-addressed media (MASTER BUILD PROMPT section 12).
 *
 * Storage layout is keyed by SHA-256, never by date. WordPress's
 * /wp-content/uploads/YYYY/MM/ pattern is part of what the legacy site is
 * being replaced for: it makes deduplication impossible and URLs mutable.
 *
 * Content addressing means a derivative URL is immutable, which is what
 * permits `Cache-Control: public, max-age=31536000, immutable` at the edge.
 *
 * Scale: ~75,000 originals and ~1.1M derivatives at 15,000 products
 * (docs/ARCHITECTURE.md section 8).
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->create('media', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->string('disk')->default('media');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime', 100);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes');

            // Deduplication key. Identical bytes are stored once regardless of
            // how many products reference them — significant when a supplier
            // ships the same image across a product family.
            $table->string('checksum_sha256', 64)->unique();

            // Required on any image attached to a product or article, enforced
            // at the model layer (MASTER BUILD PROMPT section 12.6). Nullable
            // here because media exists briefly before alt text is supplied.
            $table->text('alt_text')->nullable();
            $table->string('credit')->nullable();

            // Supplier images bearing third-party watermarks are flagged for
            // replacement rather than silently published (section 12.7).
            $table->boolean('is_watermarked_flag')->default(false);

            // Focal point for art-directed cropping, 0..1 in each axis.
            $table->decimal('focal_x', 4, 3)->default(0.5);
            $table->decimal('focal_y', 4, 3)->default(0.5);

            // Provenance, so an Icecat image can be re-fetched or audited.
            $table->string('source')->default('manual');
            $table->text('source_url')->nullable();

            $table->timestamps();

            $table->index('is_watermarked_flag');
            $table->index('source');
        });

        Schema::connection($this->connection)->create('media_derivatives', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('media_id');

            // thumb 160 | card 400 | pdp 800 | zoom 1600 | og 1200x630
            $table->string('variant_key', 32);
            $table->string('format', 8); // avif | webp | jpeg
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('path');
            $table->unsignedBigInteger('bytes');

            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->unique(['media_id', 'variant_key', 'format']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('media_derivatives');
        Schema::connection($this->connection)->dropIfExists('media');
    }
};
