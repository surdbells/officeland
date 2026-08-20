<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaStorage;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes media referenced by nothing (MASTER BUILD PROMPT section 12.8).
 *
 * Local-disk storage means durability and hygiene are both ours. Without this,
 * every rejected product image, every replaced photo and every failed import
 * accumulates forever across ~50 GB of derivatives.
 *
 * Defaults to a dry run: deleting image files is not something to do by
 * accident, and the report alone is useful.
 */
final class ReapOrphanedMedia extends Command
{
    protected $signature = 'media:reap
                            {--apply : Actually delete. Without this the command only reports.}
                            {--min-age-hours=24 : Ignore media newer than this, so an in-progress upload is never reaped.}';

    protected $description = 'Report or delete media that no product, variant, article or brand references';

    public function handle(MediaStorage $storage): int
    {
        $minAgeHours = (int) $this->option('min-age-hours');
        $apply = (bool) $this->option('apply');

        $orphans = Media::query()
            ->where('created_at', '<', now()->subHours($minAgeHours))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('product_media')->whereColumn('product_media.media_id', 'media.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('product_variants')->whereColumn('product_variants.image_media_id', 'media.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('articles')->whereColumn('articles.hero_media_id', 'media.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('categories')->whereColumn('categories.hero_media_id', 'media.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('brands')->whereColumn('brands.logo_media_id', 'media.id'))
            ->with('derivatives')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('No orphaned media.');

            return self::SUCCESS;
        }

        $bytes = 0;
        $files = 0;

        foreach ($orphans as $media) {
            $bytes += (int) $media->bytes;
            $files++;

            foreach ($media->derivatives as $derivative) {
                $bytes += (int) $derivative->bytes;
                $files++;

                if ($apply) {
                    $storage->delete($derivative->path);
                }
            }

            if ($apply) {
                $storage->delete($media->path);
                $media->delete();
            }
        }

        $this->{$apply ? 'info' : 'warn'}(sprintf(
            '%s %d orphaned media (%d files, %s MB).',
            $apply ? 'Deleted' : 'Would delete',
            $orphans->count(),
            $files,
            number_format($bytes / 1_048_576, 1),
        ));

        if (! $apply) {
            $this->line('Re-run with --apply to delete.');
        }

        return self::SUCCESS;
    }
}
