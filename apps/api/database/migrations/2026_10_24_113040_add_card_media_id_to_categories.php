<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SQUARE card image for a category, separate from its horizontal hero.
 *
 * `hero_media_id` is the wide banner the category page and the featured
 * homepage heroes render. The homepage department cards and the megamenu want
 * a 1:1 crop instead - a wide hero letterboxed into a square card looks wrong,
 * and a square cropped to a wide hero loses its subject. So a category carries
 * two images: `hero_media_id` (horizontal) and `card_media_id` (square). Both
 * nullable; the storefront falls back to the hero, then to a typographic tile.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_direct';

    public function up(): void
    {
        Schema::connection($this->connection)->table('categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('card_media_id')->nullable()->after('hero_media_id');
            $table->foreign('card_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('categories', function (Blueprint $table): void {
            $table->dropForeign(['card_media_id']);
            $table->dropColumn('card_media_id');
        });
    }
};
