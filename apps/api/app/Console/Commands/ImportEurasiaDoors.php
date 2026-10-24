<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\DerivativeGenerator;
use App\Media\MediaIngestor;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Import the Eurasia Door PVC-coated interior door range as draft products.
 *
 * The door photos and model codes come from the manufacturer (eurasiadoor.net);
 * OfficeLand resells the range in Nigeria. Each door is created as a DRAFT with
 * an ORIGINAL description (never the manufacturer's marketing prose), filed under
 * PVC doors, with the manufacturer photo downloaded through the media pipeline
 * and its derivatives generated inline. Drafts, so nothing is published until a
 * person reviews it and confirms the images may be used.
 *
 * Idempotent: products are keyed by slug, so a re-run updates rather than
 * duplicates, and a photo is only attached when the product has none.
 *
 *   php artisan catalogue:import-eurasia-doors            # report
 *   php artisan catalogue:import-eurasia-doors --apply
 */
final class ImportEurasiaDoors extends Command
{
    protected $signature = 'catalogue:import-eurasia-doors {--apply : Create the products and import the photos.}';

    protected $description = 'Import the Eurasia Door PVC interior door range as draft products';

    private const CATEGORY_PATH = '/pvc-doors-interiors/pvc-doors/internal-doors/';

    private const BASE = 'https://www.eurasiadoor.net/wp-content/uploads/2025/09/';

    /** @var list<array{code: string, file: string, name: string, desc: string}> */
    private const DOORS = [
        ['code' => 'P1', 'file' => 'P1.png', 'name' => 'PVC-Coated Interior Door — P1', 'desc' => 'A clean, flat PVC-coated interior door on a solid MDF core, finished in a warm neutral wood tone that suits homes, offices and hospitality fit-outs. The PVC surface resists scuffs and moisture and wipes clean, which holds up well in busy Nigerian interiors.'],
        ['code' => 'P2', 'file' => 'P2.png', 'name' => 'PVC-Coated Interior Door — P2', 'desc' => 'A smooth flat-panel PVC-coated door with a subtle grain, built for everyday interior use. The sealed PVC skin shrugs off humidity and fingerprints better than paint or veneer, making it a low-maintenance choice for offices and residential rooms.'],
        ['code' => 'P3', 'file' => 'P3.png', 'name' => 'PVC-Coated Interior Door — P3 (Dark Finish)', 'desc' => 'A flat interior door in a deep, dark wood-effect PVC finish for a more formal look — executive offices, boardroom entrances and feature rooms. The dark surface hides marks well and keeps its colour without the fading that paint suffers in bright light.'],
        ['code' => 'P4', 'file' => 'P4.png', 'name' => 'Moulded PVC-Coated Interior Door — P4', 'desc' => 'A moulded-panel PVC-coated door that adds classic depth and detail to a doorway, wrapped in a durable PVC skin over an MDF core. A good fit where a plain flat leaf would look too bare — receptions, homes and hospitality spaces.'],
        ['code' => 'P5', 'file' => 'P5.png', 'name' => 'PVC-Wrapped MDF Interior Door — P5', 'desc' => 'A PVC-wrapped MDF interior door with a seamless, easy-clean surface and a natural wood-grain finish. The wrap seals the edges and face in one skin, so there are no paint lines to chip — practical for high-traffic corridors and offices.'],
        ['code' => 'P8', 'file' => 'P8.png', 'name' => 'PVC-Coated Interior Door — P8', 'desc' => 'A contemporary flat PVC-coated interior door with a light, even finish that brightens rooms and pairs with most frames and handles. The moisture-resistant PVC surface is well suited to Nigeria\'s climate and keeps its look with simple wiping.'],
        ['code' => 'P11', 'file' => 'P11.png', 'name' => 'PVC-Coated Interior Door — P11', 'desc' => 'A flat PVC-coated interior door in a mid wood tone, a versatile choice for apartments, schools and offices. The hard PVC skin over an MDF core resists everyday knocks and cleans easily, so it stays presentable through heavy use.'],
        ['code' => 'P12', 'file' => 'P12.png', 'name' => 'PVC-Coated Interior Door — P12', 'desc' => 'A simple flat-faced PVC-coated door with a soft grain, designed to blend into any interior scheme. Its sealed surface resists humidity and stains, making it a dependable, low-upkeep door for bedrooms, offices and meeting rooms.'],
        ['code' => 'P13', 'file' => 'P13.png', 'name' => 'Flat PVC-Coated Interior Door — P13', 'desc' => 'A flat-design PVC-coated interior door for a clean, modern doorway. The minimalist face works well in contemporary offices and homes, and the PVC coating keeps maintenance to an occasional wipe rather than repainting.'],
        ['code' => 'P14', 'file' => 'P14.png', 'name' => 'PVC-Wrapped Interior Door — P14', 'desc' => 'A PVC-wrapped interior door with a smooth, hard-wearing finish and a realistic wood-grain effect. The one-piece wrap resists moisture and scuffing, which makes it a solid specification for busy residential and commercial interiors.'],
        ['code' => 'M1', 'file' => 'ilave1.png', 'name' => 'PVC-Coated Interior Door — M1', 'desc' => 'A flat PVC-coated interior door in a balanced neutral finish, an easy match for most walls, frames and floors. Built on an MDF core with a sealed PVC face that cleans up with a damp cloth and holds its colour over time.'],
        ['code' => 'M2', 'file' => 'ilave2.png', 'name' => 'Modern PVC-Coated Interior Door — M2', 'desc' => 'A modern-design PVC-coated interior door with crisp lines for a current, uncluttered look. The durable PVC surface suits offices and upmarket homes, resisting marks and moisture where a painted door would need regular touching up.'],
        ['code' => 'M3', 'file' => 'ilave3.png', 'name' => 'Blue Flat PVC-Coated Interior Door — M3', 'desc' => 'A flat PVC-coated interior door in a distinctive blue, for interiors that want a pop of colour rather than another wood tone — children\'s rooms, creative studios and feature doorways. The PVC finish keeps the colour vivid and wipes clean.'],
        ['code' => 'M4', 'file' => 'ilave4.png', 'name' => 'PVC-Coated Interior Door — M4', 'desc' => 'A flat PVC-coated interior door with a understated grain and a hard, sealed surface. A practical, good-value interior leaf for offices, schools and homes that stands up to daily use and needs no painting or varnishing.'],
    ];

    public function handle(MediaIngestor $ingestor, DerivativeGenerator $derivatives): int
    {
        @ini_set('memory_limit', '512M');

        $apply = (bool) $this->option('apply');

        $category = Category::query()->where('path', self::CATEGORY_PATH)->first();
        if ($category === null) {
            $this->error('Category not found: '.self::CATEGORY_PATH);

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $withImage = 0;
        $failed = 0;

        foreach (self::DOORS as $door) {
            $slug = Str::slug($door['name']);
            $this->line("  {$door['code']}  {$door['name']}");

            if (! $apply) {
                continue;
            }

            try {
                $product = Product::query()->where('slug', $slug)->first();
                $isNew = $product === null;

                $product = Product::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $door['name'],
                        'short_description' => Str::limit($door['desc'], 155, ''),
                        'description_html' => '<p>'.e($door['desc']).'</p>',
                        'primary_category_id' => $category->id,
                        'model_code' => $door['code'],
                        'availability' => 'lead_time',
                        // source stays 'manual' (its default; the schema allows only
                        // icecat/csv/manual). The Eurasia origin is recorded on the
                        // media row's source/source_url instead.
                        // status defaults to draft; left draft on purpose for review.
                    ],
                );

                $product->categories()->syncWithoutDetaching([$category->id => ['sort_order' => 0]]);

                $isNew ? $created++ : $updated++;

                // Attach the manufacturer photo only when the product has none.
                if (! $product->media()->exists()) {
                    $url = self::BASE.$door['file'];
                    $tmp = tempnam(sys_get_temp_dir(), 'door').'.png';
                    $response = Http::timeout(45)->retry(2, 1500, throw: false)->sink($tmp)->get($url);

                    if (! $response->successful()) {
                        $this->warn("    image download failed ({$response->status()}): {$url}");
                        $failed++;
                        @unlink($tmp);

                        continue;
                    }

                    $media = $ingestor->ingest($tmp, [
                        'alt_text' => $door['name'],
                        'source' => 'eurasia',
                        'source_url' => $url,
                    ]);
                    @unlink($tmp);

                    $product->media()->syncWithoutDetaching([
                        $media->id => ['role' => 'primary', 'sort_order' => 0],
                    ]);

                    // Inline: 14 doors is a small batch, and generating here avoids
                    // depending on the media queue worker.
                    $derivatives->generate($media);
                    $withImage++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("    failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->table(['Outcome', 'Count'], [
            [$apply ? 'Created' : 'Would import', $apply ? $created : count(self::DOORS)],
            ['Updated (already existed)', $updated],
            ['Photos imported', $withImage],
            ['Failed', $failed],
        ]);

        if (! $apply) {
            $this->comment('Dry run. Add --apply to create the draft products and import the photos.');
        } else {
            $this->info('Created as DRAFT under '.self::CATEGORY_PATH.'. Review and publish in the admin.');
        }

        return self::SUCCESS;
    }
}
