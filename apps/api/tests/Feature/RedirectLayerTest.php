<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The redirect layer (§14a, §14b, D-007).
 *
 * ── What these protect ───────────────────────────────────────────────────
 * D-007's whole argument for keeping 5,063 legacy URLs is that they carry
 * organic equity worth more than the cost of mapping them. That argument is
 * only true if the redirects actually fire — and before this phase they did
 * not: the table, the CSV and the admin screen all existed while **nothing
 * served them**, and every legacy URL returned 200 with the homepage.
 *
 * The end-to-end HTTP verification lives in `redirects:verify`, which is the
 * real gate because only a request proves what a crawler receives. These cover
 * the generator and the importer, which is where a regression would start.
 */
final class RedirectLayerTest extends TestCase
{
    use RefreshDatabase;

    private function snippet(): string
    {
        $path = sys_get_temp_dir().'/redirects-test-'.bin2hex(random_bytes(4)).'.caddy';

        $this->artisan('redirects:publish', ['--output' => $path])->assertSuccessful();

        $contents = File::get($path);
        File::delete($path);

        return $contents;
    }

    public function test_a_moved_url_becomes_a_map_entry(): void
    {
        Redirect::query()->create([
            'from_path' => '/product-category/chairs/',
            'to_path' => '/c/office-furniture/seating/',
            'status_code' => 301,
            'source' => 'migration',
        ]);

        $snippet = $this->snippet();

        $this->assertStringContainsString(
            '"/product-category/chairs/" "/c/office-furniture/seating/" 301',
            $snippet,
        );
        $this->assertStringContainsString('redir @ol_moved {ol_to} {ol_status}', $snippet);
    }

    /**
     * A 410 must be wrapped in `handle`, not emitted as a bare `respond`.
     *
     * Caddy evaluates directives in a fixed order in which `respond` comes
     * AFTER `handle`, so a bare respond loses to the static file handler and
     * the URL 404s instead — which is exactly what happened, and the
     * difference matters: a 410 is dropped from the index faster and stops
     * being re-crawled, a 404 is retried for months.
     */
    public function test_a_gone_url_is_wrapped_in_a_handle_block(): void
    {
        Redirect::query()->create([
            'from_path' => '/wp-admin/',
            'to_path' => null,
            'status_code' => 410,
            'source' => 'migration',
        ]);

        $snippet = $this->snippet();

        $this->assertStringContainsString('@ol_gone path "/wp-admin/"', $snippet);
        $this->assertMatchesRegularExpression(
            '/handle @ol_gone \{\s*respond 410\s*\}/',
            $snippet,
            'A bare `respond` is ordered after `handle` and never fires.',
        );
    }

    /**
     * Without `default`, an unmatched path leaves the placeholder set and the
     * matcher fires for every request on the site — redirecting the whole
     * storefront to whatever the last entry happened to be.
     */
    public function test_the_map_has_a_default_so_it_cannot_match_everything(): void
    {
        Redirect::query()->create([
            'from_path' => '/old/',
            'to_path' => '/new/',
            'status_code' => 301,
            'source' => 'migration',
        ]);

        $this->assertStringContainsString('default "" ""', $this->snippet());
    }

    /**
     * Caddy treats whitespace as a separator and `{}` as placeholders. A
     * legacy URL containing either would become a syntax error at reload —
     * on a production edge, mid-cutover.
     */
    public function test_paths_are_quoted(): void
    {
        Redirect::query()->create([
            'from_path' => '/old page/',
            'to_path' => '/c/',
            'status_code' => 301,
            'source' => 'migration',
        ]);

        $this->assertStringContainsString('"/old page/" "/c/" 301', $this->snippet());
    }

    public function test_an_empty_table_produces_a_snippet_with_no_matchers(): void
    {
        $snippet = $this->snippet();

        // The directives, not the words: the header comment legitimately
        // contains "redirects", and asserting on that matched the comment
        // rather than the config.
        $this->assertStringNotContainsString('redir @ol_moved', $snippet);
        $this->assertStringNotContainsString('respond 410', $snippet);
        $this->assertStringNotContainsString('map {path}', $snippet);
    }

    // -----------------------------------------------------------------
    // Import validation
    // -----------------------------------------------------------------

    /** @param list<string> $rows */
    private function csv(array $rows): string
    {
        $path = sys_get_temp_dir().'/redirect-map-'.bin2hex(random_bytes(4)).'.csv';

        File::put($path, implode("\n", array_merge(['from_path,to_path,status,notes'], $rows))."\n");

        return $path;
    }

    public function test_it_imports_valid_rows(): void
    {
        $file = $this->csv(['/old/,/new/,301,moved', '/junk/,,410,deliberately gone']);

        $this->artisan('redirects:import', ['--file' => $file, '--apply' => true])->assertSuccessful();

        File::delete($file);

        $this->assertSame(2, Redirect::query()->count());
        $this->assertNull(Redirect::query()->where('from_path', '/junk/')->first()?->to_path);
    }

    /**
     * A malformed row names its line rather than aborting the import with a
     * database constraint violation — the difference between a fixable report
     * and a stack trace.
     *
     * @return array<string, array{string, string}>
     */
    public static function badRows(): array
    {
        return [
            'relative from' => ['old/,/new/,301,', 'absolute path'],
            'bad status' => ['/old/,/new/,307,', 'must be 301, 302 or 410'],
            '410 with a target' => ['/old/,/new/,410,', '410 must have no destination'],
            'redirect to nowhere' => ['/old/,,301,', 'needs an absolute destination'],
            'self redirect' => ['/old/,/old/,301,', 'redirects to itself'],
        ];
    }

    #[DataProvider('badRows')]
    public function test_it_rejects_a_malformed_row(string $row, string $expected): void
    {
        $file = $this->csv([$row]);

        $this->artisan('redirects:import', ['--file' => $file, '--apply' => true])
            ->expectsOutputToContain($expected)
            ->assertFailed();

        File::delete($file);

        $this->assertSame(0, Redirect::query()->count());
    }

    /**
     * Rows added through the admin, and rows auto-inserted by a slug change,
     * exist only in the table. A re-import must not delete them.
     */
    public function test_import_never_deletes_rows_it_did_not_write(): void
    {
        Redirect::query()->create([
            'from_path' => '/added-by-hand/',
            'to_path' => '/c/',
            'status_code' => 301,
            'source' => 'manual',
        ]);

        $file = $this->csv(['/old/,/new/,301,']);

        $this->artisan('redirects:import', ['--file' => $file, '--apply' => true])->assertSuccessful();

        File::delete($file);

        $this->assertNotNull(Redirect::query()->where('from_path', '/added-by-hand/')->first());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $file = $this->csv(['/old/,/new/,301,']);

        $this->artisan('redirects:import', ['--file' => $file])->assertSuccessful();

        File::delete($file);

        $this->assertSame(0, Redirect::query()->count());
    }
}
