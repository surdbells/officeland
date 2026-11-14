<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Icecat\IcecatSuppliers;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use XMLReader;

/**
 * Look up Icecat supplier ids by brand name.
 *
 * The importer's brand dropdown is driven by config('icecat.suppliers'), which
 * maps our brand keys to Icecat supplier ids. Adding a brand needs its exact id,
 * and the authoritative list (SuppliersList.xml) is only reachable with the
 * account credentials on the whitelisted server - so this prints the matches
 * there rather than anyone guessing an id.
 *
 *   php artisan catalogue:icecat-supplier-ids casio anker apple apc havit transcend genius
 */
final class IcecatSupplierIds extends Command
{
    protected $signature = 'catalogue:icecat-supplier-ids
        {names* : Brand names to look up (case-insensitive; partial names match).}
        {--refresh : Re-download the suppliers reference first.}';

    protected $description = 'Find the Icecat supplier id for one or more brand names';

    public function handle(): int
    {
        $path = IcecatSuppliers::ensureCached((bool) $this->option('refresh'));
        if ($path === null) {
            $this->error('Could not load the Icecat suppliers reference. Check ICECAT_USERNAME/ICECAT_PASSWORD and that this server\'s IP is whitelisted.');

            return self::FAILURE;
        }

        $wanted = array_map(fn (string $n): string => Str::lower(trim($n)), (array) $this->argument('names'));
        $configured = array_flip(array_map('intval', (array) config('icecat.suppliers')));
        $rows = [];

        $reader = new XMLReader;
        $reader->open('compress.zlib://'.$path, null, LIBXML_NONET);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Supplier') {
                continue;
            }

            $id = (int) ($reader->getAttribute('ID') ?? 0);
            $name = (string) ($reader->getAttribute('Name') ?? '');
            $lower = Str::lower($name);

            foreach ($wanted as $w) {
                // Exact name, or a name that starts with the query (e.g. "APC by Schneider").
                if ($lower === $w || Str::startsWith($lower, $w.' ') || Str::startsWith($lower, $w.'-')) {
                    $rows[] = [
                        $w,
                        $id,
                        $name,
                        $reader->getAttribute('Sponsor') ?? $reader->getAttribute('IsSponsor') ?? '?',
                        isset($configured[$id]) ? 'yes' : 'no',
                    ];
                }
            }
        }
        $reader->close();

        if ($rows === []) {
            $this->warn('No supplier matched those names.');

            return self::SUCCESS;
        }

        usort($rows, fn (array $a, array $b): int => [$a[0], $a[2]] <=> [$b[0], $b[2]]);
        $this->table(['Query', 'Icecat id', 'Icecat name', 'Sponsor (open data)', 'Already configured'], $rows);
        $this->line('Sponsor = 1 means the brand publishes full data-sheets on the free tier; 0 means mostly limited sheets.');

        return self::SUCCESS;
    }
}
