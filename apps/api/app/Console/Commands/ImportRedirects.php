<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Redirect;
use Illuminate\Console\Command;

/**
 * Loads `docs/REDIRECT_MAP.csv` into the `redirects` table (§14a).
 *
 * ── Why the CSV is the source and the table is the copy ──────────────────
 * The map is reviewed, argued over and diffed in pull requests — a spreadsheet
 * of "this legacy URL becomes that page" is an editorial artefact, not runtime
 * data. Keeping it in Git means a bad mapping is caught in review; keeping it
 * only in a table means it is caught by a customer.
 *
 * Rows added later through the admin, and rows auto-inserted on a slug change,
 * live only in the table. This import therefore updates and inserts but never
 * deletes.
 */
final class ImportRedirects extends Command
{
    protected $signature = 'redirects:import
                            {--file= : CSV to read. Defaults to docs/REDIRECT_MAP.csv.}
                            {--apply : Write. Without this the command only reports.}';

    protected $description = 'Import the legacy redirect map from CSV';

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?? '')
            ?: '/docs/REDIRECT_MAP.csv';

        if (! is_file($path)) {
            $this->error("No redirect map at {$path}.");

            return self::FAILURE;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            $this->error("Could not open {$path}.");

            return self::FAILURE;
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error('The redirect map is empty.');
            fclose($handle);

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $rejected = [];

        /*
         * Every from_path the CSV mentions, so rows it no longer mentions can
         * be removed at the end.
         *
         * Without this the importer only ever added. Deleting a line from the
         * map left the redirect live, and the CSV stopped being the source of
         * truth the moment anybody needed to retire one. Found the hard way:
         * /shop/ was a legacy WooCommerce redirect to /c/, the storefront grew
         * a real /shop/ page, the line was deleted, the import reported "181
         * already correct" — and /shop/ still answered 301.
         *
         * Scoped to source = 'migration' so redirects created in the admin,
         * which were never in this file, are not swept away by it.
         */
        $seen = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            // fgetcsv returns [null] for a blank line rather than an empty
            // array, and a trailing newline is normal in a hand-edited CSV.
            if ($row === [null]) {
                continue;
            }

            /** @var list<string> $columns */
            $columns = array_map(strval(...), array_slice($header, 0, count($row)));

            /** @var list<string> $values */
            $values = array_map(strval(...), $row);

            $data = array_combine($columns, $values);

            $from = trim((string) ($data['from_path'] ?? ''));
            $to = trim((string) ($data['to_path'] ?? ''));
            $status = (int) ($data['status'] ?? 301);

            /*
             * Validated here rather than relying on the database CHECK
             * constraints, so a malformed row names its line number instead of
             * aborting the import with a constraint violation.
             */
            $problem = $this->problemWith($from, $to, $status);

            if ($problem !== null) {
                $rejected[] = "line {$line}: {$problem}";

                continue;
            }

            $seen[] = $from;

            $existing = Redirect::query()->where('from_path', $from)->first();

            $attributes = [
                'to_path' => $status === 410 ? null : $to,
                'status_code' => $status,
                'source' => 'migration',
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ];

            if ($existing === null) {
                $created++;

                if ($apply) {
                    Redirect::query()->create(['from_path' => $from] + $attributes);
                }

                continue;
            }

            $differs = $existing->to_path !== $attributes['to_path']
                || (int) $existing->status_code !== $status;

            if (! $differs) {
                $unchanged++;

                continue;
            }

            $updated++;

            if ($apply) {
                $existing->forceFill($attributes)->save();
            }
        }

        fclose($handle);

        // Rows this file used to carry and no longer does. See $seen above.
        $stale = Redirect::query()
            ->where('source', 'migration')
            ->whereNotIn('from_path', $seen)
            ->pluck('from_path')
            ->all();

        if ($apply && $stale !== []) {
            Redirect::query()
                ->where('source', 'migration')
                ->whereIn('from_path', $stale)
                ->delete();
        }

        $this->table(['Outcome', 'Rows'], [
            [$apply ? 'Created' : 'Would create', $created],
            [$apply ? 'Updated' : 'Would update', $updated],
            ['Already correct', $unchanged],
            [$apply ? 'Removed' : 'Would remove', count($stale)],
            ['Rejected', count($rejected)],
        ]);

        foreach (array_slice($stale, 0, 20) as $path) {
            $this->comment(($apply ? 'Removed' : 'Would remove').": {$path} — no longer in the map.");
        }

        foreach (array_slice($rejected, 0, 20) as $problem) {
            $this->warn($problem);
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply to write, then `redirects:publish`.');
        }

        return $rejected === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return string|null the reason this row cannot be imported */
    private function problemWith(string $from, string $to, int $status): ?string
    {
        if ($from === '' || ! str_starts_with($from, '/')) {
            return "from_path must be an absolute path, got '{$from}'";
        }

        if (! in_array($status, [301, 302, 410], true)) {
            return "status must be 301, 302 or 410, got {$status}";
        }

        if ($status === 410) {
            return $to === '' ? null : '410 must have no destination';
        }

        if ($to === '' || ! str_starts_with($to, '/')) {
            return "a {$status} needs an absolute destination, got '{$to}'";
        }

        // The database rejects this too, but a self-redirect in a hand-edited
        // CSV is common enough to be worth naming by line.
        if ($from === $to) {
            return "redirects to itself: {$from}";
        }

        return null;
    }
}
