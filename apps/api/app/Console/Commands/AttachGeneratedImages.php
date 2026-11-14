<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateMediaDerivatives;
use App\Media\MediaIngestor;
use App\Models\Category;
use App\Models\HeroSlide;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Attach the commissioned (AI-generated) storefront imagery from a manifest.
 *
 * The images are generated off-platform; this is the one place they enter the
 * catalogue. The manifest (database/data/generated-images.json) maps:
 *
 *   categories.{path}.hero  -> categories.hero_media_id  (the wide page banner)
 *   categories.{path}.card  -> categories.card_media_id  (the square tile)
 *   hero_slides[]           -> hero_slides rows (homepage stage), keyed on headline
 *
 * Each image goes through the normal media pipeline (validation, EXIF strip,
 * content-addressed store, queued derivatives) with alt text written from the
 * category or slide, never left blank. Idempotent: a category that already has
 * a hero/card is left alone unless --force, and a slide is matched on its
 * headline so a re-run updates it rather than adding a duplicate.
 *
 * The manifest URLs are signed and expire; if a download fails with 403, ask
 * for a refreshed manifest rather than retrying.
 *
 *   php artisan catalogue:attach-generated-images            # dry run
 *   php artisan catalogue:attach-generated-images --apply
 *   php artisan catalogue:attach-generated-images --apply --force
 */
final class AttachGeneratedImages extends Command
{
    protected $signature = 'catalogue:attach-generated-images
        {--manifest= : Manifest path (default database/data/generated-images.json).}
        {--apply : Write. Without this it only reports.}
        {--force : Replace category images that are already set.}
        {--only= : Limit to "heroes", "cards" or "slides".}
        {--replace-slides : Deactivate active hero slides that are not in the manifest (kept, not deleted).}';

    protected $description = 'Attach generated category heroes, category cards and homepage hero slides from a manifest';

    private int $attached = 0;

    private int $skipped = 0;

    private int $failed = 0;

    public function handle(MediaIngestor $ingestor): int
    {
        @ini_set('memory_limit', '512M');

        $path = (string) ($this->option('manifest') ?: database_path('data/generated-images.json'));
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($manifest)) {
            $this->error("No readable manifest at {$path}.");

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $only = (string) $this->option('only');

        foreach ((array) ($manifest['categories'] ?? []) as $categoryPath => $images) {
            $category = Category::query()->where('path', $categoryPath)->first();
            if ($category === null) {
                $this->warn("  no category at {$categoryPath}");
                $this->failed++;

                continue;
            }

            foreach (['hero' => 'hero_media_id', 'card' => 'card_media_id'] as $kind => $column) {
                $url = $images[$kind] ?? null;
                if ($url === null || ($only !== '' && $only !== $kind.'s')) {
                    continue;
                }

                if ($category->{$column} !== null && ! $this->option('force')) {
                    $this->skipped++;

                    continue;
                }

                $alt = $kind === 'hero'
                    ? "{$category->name} from OfficeLand"
                    : "{$category->name} - shop the range at OfficeLand";

                if (! $apply) {
                    $this->line("  would set {$kind} for {$categoryPath}");
                    $this->attached++;

                    continue;
                }

                $mediaId = $this->ingest($ingestor, $url, $alt, Str::slug($category->name)."-{$kind}");
                if ($mediaId !== null) {
                    $category->update([$column => $mediaId]);
                    $this->attached++;
                }
            }
        }

        if ($only === '' || $only === 'slides') {
            foreach ((array) ($manifest['hero_slides'] ?? []) as $order => $slide) {
                $headline = (string) ($slide['headline'] ?? '');
                $url = $slide['image'] ?? null;
                if ($headline === '' || $url === null) {
                    continue;
                }

                if (! $apply) {
                    $this->line("  would set slide \"{$headline}\"");
                    $this->attached++;

                    continue;
                }

                $mediaId = $this->ingest($ingestor, $url, (string) ($slide['alt'] ?? $headline), Str::slug($headline).'-slide');
                if ($mediaId === null) {
                    continue;
                }

                $row = HeroSlide::query()->firstOrNew(['headline' => $headline]);
                if (! $row->exists) {
                    $row->uuid = (string) Str::uuid();
                }
                $row->fill([
                    'subheadline' => $slide['subheadline'] ?? null,
                    'cta_label' => $slide['cta_label'] ?? null,
                    'cta_url' => $slide['cta_url'] ?? null,
                    'secondary_cta_label' => $slide['secondary_cta_label'] ?? null,
                    'secondary_cta_url' => $slide['secondary_cta_url'] ?? null,
                    'media_id' => $mediaId,
                    'is_active' => true,
                    'sort_order' => (int) $order,
                ])->save();
                $this->attached++;
            }
        }

        if ($apply && $this->option('replace-slides') && ($only === '' || $only === 'slides')) {
            $headlines = array_values(array_filter(array_map(
                fn ($slide): string => (string) ($slide['headline'] ?? ''),
                (array) ($manifest['hero_slides'] ?? []),
            )));
            if ($headlines !== []) {
                $off = HeroSlide::query()->where('is_active', true)->whereNotIn('headline', $headlines)->update(['is_active' => false]);
                $this->line("  deactivated {$off} slide(s) not in the manifest");
            }
        }

        if ($apply) {
            // The navigation payload carries category images and is
            // cached; drop them so the new imagery shows at once.
            Cache::forget('api:navigation:v1');
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Attached' : 'Would attach', $this->attached],
            ['Skipped (already set; --force to replace)', $this->skipped],
            ['Failed', $this->failed],
        ]);

        if (! $apply) {
            $this->comment('Dry run. Add --apply to attach.');
        }

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function ingest(MediaIngestor $ingestor, string $url, string $alt, string $name): ?int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gen').'-'.$name.'.png';

        try {
            $response = Http::connectTimeout(20)->timeout(300)->retry(2, 2000, throw: false)->sink($tmp)->get($url);
            if (! $response->successful()) {
                $this->warn("  download failed ({$response->status()}) for {$name} - the signed URL may have expired");
                $this->failed++;

                return null;
            }

            $media = $ingestor->ingest($tmp, [
                'alt_text' => $alt,
                'source' => 'generated',
                'source_url' => strtok($url, '?') ?: null,
            ]);
            GenerateMediaDerivatives::dispatch($media->id);
            $this->line("  attached {$name} (media #{$media->id})");

            return $media->id;
        } catch (Throwable $e) {
            $this->warn("  {$name}: ".$e->getMessage());
            $this->failed++;

            return null;
        } finally {
            @unlink($tmp);
        }
    }
}
