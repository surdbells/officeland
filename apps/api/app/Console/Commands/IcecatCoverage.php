<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatClient;
use App\Icecat\IcecatIndex;
use Illuminate\Console\Command;

/**
 * How much of each brand the Icecat index will actually let us import.
 *
 * An import scans the index and keeps only entries that are on the market and
 * NOT Limited="Yes" - limited data-sheets are the ones a brand has not paid
 * Icecat to open, and the Open Icecat tier cannot fetch them in full. A brand
 * that does not sponsor Open Icecat is therefore almost entirely limited, and
 * an import run for it creates nothing without saying why. This counts it out
 * per brand, and --probe fetches one limited sheet to show what this account
 * really gets back for it.
 *
 *   php artisan catalogue:icecat-coverage casio genius havit anker apc apple transcend --probe
 */
final class IcecatCoverage extends Command
{
    protected $signature = 'catalogue:icecat-coverage
        {brands* : Brand keys from config(icecat.suppliers)}
        {--probe : Fetch one limited data-sheet per brand and report what comes back}';

    protected $description = 'Count importable vs limited Icecat products per brand';

    public function handle(IcecatClient $client): int
    {
        /** @var array<string, int|string> $all */
        $all = (array) config('icecat.suppliers');
        $suppliers = [];

        foreach ((array) $this->argument('brands') as $key) {
            $key = strtolower(trim((string) $key));
            if (! isset($all[$key])) {
                $this->warn("Unknown brand key '{$key}' - not in config/icecat.php suppliers.");

                continue;
            }
            $suppliers[(int) $all[$key]] = $key;
        }

        if ($suppliers === []) {
            return self::FAILURE;
        }

        $path = IcecatIndex::ensureCached();
        if ($path === null) {
            $this->error('Could not load the Icecat index. Check credentials and IP whitelisting.');

            return self::FAILURE;
        }

        $this->info('Scanning the index (a few minutes for the full file)...');

        $counts = [];
        $sample = [];
        foreach ($suppliers as $id => $key) {
            $counts[$id] = ['total' => 0, 'off_market' => 0, 'limited' => 0, 'importable' => 0];
        }

        foreach (IcecatIndex::stream('compress.zlib://'.$path, array_keys($suppliers), onMarketOnly: false, fullOnly: false) as $entry) {
            $c = &$counts[$entry['supplier_id']];
            $c['total']++;

            if (! $entry['on_market']) {
                $c['off_market']++;
            } elseif ($entry['limited']) {
                $c['limited']++;
                $sample[$entry['supplier_id']] ??= $entry['product_id'];
            } else {
                $c['importable']++;
            }
            unset($c);
        }

        $rows = [];
        foreach ($suppliers as $id => $key) {
            $c = $counts[$id];
            $rows[] = [$key, $id, $c['total'], $c['off_market'], $c['limited'], $c['importable']];
        }

        $this->table(['Brand', 'Supplier id', 'In index', 'Off market', 'Limited (skipped)', 'Importable'], $rows);

        if ($this->option('probe')) {
            $probe = [];
            foreach ($suppliers as $id => $key) {
                $productId = $sample[$id] ?? null;
                if ($productId === null) {
                    $probe[] = [$key, '-', 'no limited entries', '', '', ''];

                    continue;
                }

                $doc = $client->fetchById($productId);
                $probe[] = $doc === null
                    ? [$key, $productId, 'not returned', '', '', '']
                    : [
                        $key,
                        $productId,
                        'returned',
                        mb_strimwidth((string) $doc->title(), 0, 50, '...'),
                        count($doc->imageUrls()),
                        count($doc->featureGroups()),
                    ];
            }

            $this->newLine();
            $this->info('One limited data-sheet per brand, fetched with this account:');
            $this->table(['Brand', 'Icecat id', 'Fetch', 'Title', 'Images', 'Spec groups'], $probe);
        }

        return self::SUCCESS;
    }
}
