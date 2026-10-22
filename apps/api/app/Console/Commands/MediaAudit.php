<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaStorage;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Find the media that render as a broken image on the storefront.
 *
 * ImageResource emits `src: null` and no `<source>` when a media has no
 * derivative rows (ImageResource.php): the admin preview still shows the
 * original, so such an image "works in admin but is broken on the site" - the
 * exact symptom reported. A media ends up here two ways:
 *
 *   - original present, derivatives never generated  -> fixable: re-run
 *     catalogue:backfill-derivatives (or --fix here) to queue generation.
 *   - original missing on disk                        -> NOT fixable by
 *     generation (the generator throws on a missing source); the media row is
 *     dead and should be detached/reaped.
 *
 * This reports the split so the next step is obvious, and names a few affected
 * products so the result can be eyeballed on the live site.
 *
 *   php artisan media:audit
 *   php artisan media:audit --fix     # queue derivative generation for the fixable ones
 */
final class MediaAudit extends Command
{
    protected $signature = 'media:audit
        {--fix : Queue derivative generation for media whose original is present.}
        {--samples=10 : How many affected products to list.}';

    protected $description = 'Report media with no derivatives (broken on the storefront) and why';

    public function handle(MediaStorage $storage): int
    {
        @ini_set('memory_limit', '512M');

        $withoutDerivatives = Media::query()->whereDoesntHave('derivatives')->count();
        $total = Media::query()->count();

        $this->info("Media rows: {$total}");
        $this->info("Media with NO derivatives (broken on storefront): {$withoutDerivatives}");

        if ($withoutDerivatives === 0) {
            $this->info('Every media has derivatives. Any broken image you still see is a cached 404 - hard-refresh (Ctrl+Shift+R) or purge the Cloudflare cache.');

            return self::SUCCESS;
        }

        $originalPresent = 0;
        $originalMissing = 0;
        $fixable = [];
        $deadMediaIds = [];

        Media::query()
            ->whereDoesntHave('derivatives')
            ->orderBy('id')
            ->chunkById(500, function ($media) use ($storage, &$originalPresent, &$originalMissing, &$fixable, &$deadMediaIds): void {
                foreach ($media as $m) {
                    if ($storage->exists($m->path)) {
                        $originalPresent++;
                        $fixable[] = $m->id;
                    } else {
                        $originalMissing++;
                        $deadMediaIds[] = $m->id;
                    }
                }
            });

        $this->newLine();
        $this->table(['Cause', 'Media', 'Next step'], [
            ['Original present, derivatives missing', $originalPresent, 'media:audit --fix  (or catalogue:backfill-derivatives)'],
            ['Original missing on disk (dead row)', $originalMissing, 'media:reap --apply  / detach + purge-trashed'],
        ]);

        $this->sampleProducts(array_slice([...$fixable, ...$deadMediaIds], 0, (int) $this->option('samples')));

        if ($this->option('fix') && $fixable !== []) {
            $this->newLine();
            $this->info('Queueing derivative generation for '.count($fixable).' media with a present original…');
            foreach (array_chunk($fixable, 500) as $batch) {
                foreach ($batch as $id) {
                    \App\Jobs\GenerateMediaDerivatives::dispatch($id);
                }
            }
            $this->info('Queued. Watch with: php artisan queue:monitor media');
        } elseif (! $this->option('fix') && $originalPresent > 0) {
            $this->newLine();
            $this->comment("Add --fix to queue generation for the {$originalPresent} fixable media.");
        }

        return self::SUCCESS;
    }

    /**
     * Name a few products that carry the affected media, so the report can be
     * checked against the live site.
     *
     * @param  list<int>  $mediaIds
     */
    private function sampleProducts(array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }

        $rows = DB::table('product_media')
            ->join('products', 'products.id', '=', 'product_media.product_id')
            ->whereIn('product_media.media_id', $mediaIds)
            ->whereNull('products.deleted_at')
            ->distinct()
            ->limit((int) $this->option('samples'))
            ->get(['products.slug', 'products.name']);

        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('Affected products (spot-check on the live site):');
        foreach ($rows as $row) {
            $this->line("  /p/{$row->slug}/  -  {$row->name}");
        }
    }
}
