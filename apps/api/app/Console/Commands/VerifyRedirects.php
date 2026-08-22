<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Redirect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Phase 14b gate: every legacy URL verified end to end.
 *
 * ── Why this makes real HTTP requests ────────────────────────────────────
 * The redirects live in a table, are generated into a Caddy snippet, and are
 * served by the edge. Checking the table proves the data; checking the file
 * proves the generator. **Neither proves a browser gets a 301**, and the whole
 * of D-007 — the argument for keeping 5,063 legacy URLs' organic equity —
 * rests on what a crawler actually receives.
 *
 * Every failure this catches has been real at some point in this project: a
 * snippet that was never regenerated, a directive-order problem that turned
 * every 410 into a 404, a `try_files` fallback that answered 200 for
 * everything.
 *
 * ── The three things that fail the gate ──────────────────────────────────
 *   wrong status       a 301 that answers 200 is a legacy page still live;
 *                      one that answers 404 has thrown the equity away
 *   wrong destination  a redirect to the wrong page is worse than none: it
 *                      looks correct in a report and reads as a bait-and-switch
 *                      to a crawler
 *   chains and loops   each hop loses a little, and a loop is an outage on a
 *                      URL Google already has indexed
 *
 * A chain is checked by following the destination: if the target is ITSELF a
 * redirect, the map should have pointed at the final destination in the first
 * place. That is cheap to fix before cutover and expensive afterwards.
 */
final class VerifyRedirects extends Command
{
    protected $signature = 'redirects:verify
                            {--base= : Origin to test against. Defaults to APP_URL.}
                            {--limit=0 : Check only the first N. 0 means all.}
                            {--show=15 : How many failures to print.}';

    protected $description = 'Verify every redirect returns the exact status and destination, with no chains or loops';

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('base') ?: config('app.url')), '/');

        /** @var array<string, Redirect> $byPath */
        $byPath = Redirect::query()->get()->keyBy('from_path')->all();

        if ($byPath === []) {
            $this->error('No redirects to verify. Run `redirects:import --apply` first.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $rows = $limit > 0 ? array_slice($byPath, 0, $limit, true) : $byPath;

        $this->info(sprintf('Verifying %d redirect(s) against %s', count($rows), $base));

        $failures = [];
        $checked = 0;
        $bar = $this->output->createProgressBar(count($rows));

        foreach ($rows as $from => $redirect) {
            $problem = $this->check($base, $from, $redirect, $byPath);

            if ($problem !== null) {
                $failures[] = $problem;
            }

            $checked++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($failures === []) {
            $this->info("PASSED — {$checked} redirect(s), correct status and destination, no chains, no loops.");

            return self::SUCCESS;
        }

        foreach (array_slice($failures, 0, (int) $this->option('show')) as $failure) {
            $this->error($failure);
        }

        if (count($failures) > (int) $this->option('show')) {
            $this->warn(sprintf('…and %d more.', count($failures) - (int) $this->option('show')));
        }

        $this->error(sprintf('FAILED — %d of %d redirect(s) are wrong.', count($failures), $checked));

        return self::FAILURE;
    }

    /**
     * @param  array<string, Redirect>  $byPath
     * @return string|null the problem, or null if this redirect is correct
     */
    private function check(string $base, string $from, Redirect $redirect, array $byPath): ?string
    {
        try {
            // withoutRedirecting: the point is the response ITSELF. Following
            // it would report the final page and hide a 302 pretending to be a
            // 301, or a two-hop chain.
            $response = Http::withoutRedirecting()->timeout(15)->get($base.$from);
        } catch (Throwable $e) {
            return "{$from}: request failed — {$e->getMessage()}";
        }

        $status = $response->status();

        if ($status !== $redirect->status_code) {
            return sprintf('%s: expected %d, got %d', $from, $redirect->status_code, $status);
        }

        if ($redirect->isGone()) {
            return null;
        }

        $location = $response->header('Location');

        if ($location === '') {
            return "{$from}: {$status} with no Location header";
        }

        // Compared as a path: Caddy answers with an absolute URL, the map holds
        // a path, and a mismatch here would be an artefact of that rather than
        // a real defect.
        $actual = parse_url($location, PHP_URL_PATH) ?: $location;

        if ($actual !== $redirect->to_path) {
            return sprintf('%s: expected -> %s, got -> %s', $from, $redirect->to_path, $actual);
        }

        if ($actual === $from) {
            return "{$from}: redirects to itself";
        }

        /*
         * A chain: the destination is itself a redirect. Checked against the
         * table rather than by making a second request, because the table is
         * where it gets fixed — and because at 5,000 rows a second round trip
         * each would double an already slow gate.
         */
        if (isset($byPath[$actual])) {
            $next = $byPath[$actual];

            return sprintf(
                '%s -> %s -> %s: chain. Point the map at the final destination.',
                $from,
                $actual,
                $next->to_path ?? '410',
            );
        }

        return null;
    }
}
