<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starting synonym set (MASTER BUILD PROMPT section 16).
 *
 * Section 16 names most of these explicitly and stresses that "Nigerian-English
 * usage matters here". A buyer asking for a biro and a buyer asking for a pen
 * want the same drawer of products, and a search that returns nothing for
 * "biro" reads as a broken site rather than a vocabulary mismatch.
 *
 * Model-code equivalence is the commercially valuable half: someone reading
 * "CE320A" off a spent cartridge and someone who knows it as "128A" are the
 * same customer, and without this only one of them finds the product.
 *
 * This is a starting set, not a finished one. The real list is grown from the
 * zero-result report in Filament, which is why the table is admin-editable.
 */
final class SearchSynonymSeeder extends Seeder
{
    /** @var list<array{string, string, string}> term, synonym, type */
    private const SYNONYMS = [
        // --- Nigerian-English and general usage -------------------------
        ['biro', 'pen', 'usage'],
        ['biro', 'ballpoint', 'usage'],
        ['flash drive', 'usb', 'usage'],
        ['flash drive', 'pendrive', 'usage'],
        ['flash drive', 'thumb drive', 'usage'],
        ['photocopier', 'copier', 'usage'],
        ['photocopier', 'copy machine', 'usage'],
        ['toner', 'cartridge', 'usage'],
        ['laptop', 'notebook', 'usage'],
        ['jotter', 'notebook', 'usage'],
        ['cardboard', 'carton', 'usage'],
        ['pencil sharpener', 'sharpener', 'usage'],
        ['stapler pin', 'staple', 'usage'],
        ['office pin', 'paper clip', 'usage'],
        ['gum', 'glue', 'usage'],
        ['selotape', 'tape', 'usage'],
        ['duplicating paper', 'printing paper', 'usage'],
        ['a4 paper', 'copier paper', 'usage'],
        ['ink', 'cartridge', 'usage'],
        ['swivel chair', 'office chair', 'usage'],
        ['executive chair', 'director chair', 'usage'],
        ['work station', 'workstation', 'usage'],
        ['cupboard', 'cabinet', 'usage'],
        ['calculator', 'calculating machine', 'usage'],
        ['white board', 'whiteboard', 'usage'],
        ['marker', 'marker pen', 'usage'],
        ['file jacket', 'folder', 'usage'],
        ['shredder', 'paper shredder', 'usage'],

        // --- Model-code equivalence -------------------------------------
        // HP toner families. Section 16 names CE320A / 128A / HP 128A.
        ['ce320a', '128a', 'model_code'],
        ['ce321a', '128a', 'model_code'],
        ['ce322a', '128a', 'model_code'],
        ['ce323a', '128a', 'model_code'],
        ['cf210a', '131a', 'model_code'],
        ['cf280a', '80a', 'model_code'],
        ['cf283a', '83a', 'model_code'],
        ['ce285a', '85a', 'model_code'],
        ['q2612a', '12a', 'model_code'],
        ['ch561he', '122', 'model_code'],

        // --- Common misspellings, ONE WAY -------------------------------
        // The misspelling should find the correct term; searching the correct
        // term must not drag in the misspelling's matches.
        ['stationary', 'stationery', 'typo'],
        ['cartrige', 'cartridge', 'typo'],
        ['cartidge', 'cartridge', 'typo'],
        ['ergonomical', 'ergonomic', 'typo'],
        ['labtop', 'laptop', 'typo'],
        ['printter', 'printer', 'typo'],
    ];

    public function run(): void
    {
        $rows = [];

        foreach (self::SYNONYMS as [$term, $synonym, $type]) {
            $rows[] = [
                'term' => $term,
                'synonym' => $synonym,
                'type' => $type,
                // Typos are one-way; everything else works in both directions.
                'bidirectional' => $type !== 'typo',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('search_synonyms')->upsert($rows, ['term', 'synonym'], ['type', 'bidirectional', 'is_active', 'updated_at']);

        $this->command->info(sprintf('Seeded %d synonyms.', count($rows)));
    }
}
