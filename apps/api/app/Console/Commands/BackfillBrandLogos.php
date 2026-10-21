<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateBrandLogo;
use App\Models\Brand;
use Illuminate\Console\Command;

/**
 * Ensure every brand has a logo.
 *
 *   php artisan catalogue:brand-logos            # queue logos for brands missing one
 *   php artisan catalogue:brand-logos --force    # regenerate ALL brand logos
 *   php artisan catalogue:brand-logos --sync     # run inline instead of queuing
 *
 * Dispatches one GenerateBrandLogo job per brand; the worker sources a real
 * Icecat logo where it can and generates a monogram otherwise. Dispatch is
 * instant, so this returns immediately and the logos fill in in the background.
 */
final class BackfillBrandLogos extends Command
{
    protected $signature = 'catalogue:brand-logos
        {--force : Regenerate every brand logo, even brands that already have one.}
        {--sync : Run inline now instead of queuing (slow for many brands).}';

    protected $description = 'Give every brand a logo (real where available, generated otherwise)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');

        $query = Brand::query();

        if (! $force) {
            $query->whereNull('logo_media_id');
        }

        $brands = $query->get(['id', 'name']);

        if ($brands->isEmpty()) {
            $this->info('Every brand already has a logo. Use --force to regenerate.');

            return self::SUCCESS;
        }

        foreach ($brands as $brand) {
            $job = new GenerateBrandLogo($brand->id, $force);
            $sync ? dispatch_sync($job) : dispatch($job);
        }

        $verb = $sync ? 'Generated' : 'Queued';
        $this->info("{$verb} logos for {$brands->count()} brand(s)".($sync ? '.' : '; the worker is building them now.'));

        return self::SUCCESS;
    }
}
