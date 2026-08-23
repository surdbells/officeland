<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use Database\Seeders\CategorySeoSeeder;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * The authoring budgets for category search appearance.
 *
 * These live in a test rather than in the seeder because the seeder cannot
 * usefully check them: PHPStan can prove every string in the constant already
 * fits, so a runtime guard is dead code — which is the check passing, not the
 * check being pointless. Moving it here puts the same assertion where a pull
 * request will fail on it.
 *
 * A description over budget is not a crash; it is a snippet cut mid-clause in
 * a search result, which is the one place a stranger judges the business
 * before deciding whether to visit.
 */
final class CategorySeoSeederTest extends TestCase
{
    /** Google truncates around here on desktop, and shorter on mobile. */
    private const DESCRIPTION_BUDGET = 158;

    /** Matches SeoService.TITLE_BUDGET on the storefront. */
    private const TITLE_BUDGET = 60;

    /** @return array<string, array{0: string, 1: string}> */
    private function entries(): array
    {
        /** @var array<string, array{0: string, 1: string}> $seo */
        $seo = (new ReflectionClass(CategorySeoSeeder::class))->getConstant('SEO');

        return $seo;
    }

    public function test_every_description_fits_the_snippet_budget(): void
    {
        foreach ($this->entries() as $path => [, $description]) {
            $this->assertLessThanOrEqual(
                self::DESCRIPTION_BUDGET,
                mb_strlen($description),
                "{$path}: description is ".mb_strlen($description).' characters and will be truncated.',
            );
        }
    }

    /**
     * An over-budget title is worse than no title: the storefront uses an
     * editor title verbatim, so it would be truncated with an ellipsis rather
     * than falling back to a whole template phrase.
     */
    public function test_every_title_fits_the_title_budget(): void
    {
        foreach ($this->entries() as $path => [$title]) {
            $this->assertLessThanOrEqual(
                self::TITLE_BUDGET,
                mb_strlen($title),
                "{$path}: title is ".mb_strlen($title).' characters and will be truncated.',
            );
        }
    }

    /**
     * An empty description is the one value that would be worse than the
     * fallback it replaces — the storefront would emit nothing rather than
     * dropping back to the intro copy.
     */
    public function test_no_description_is_empty(): void
    {
        foreach ($this->entries() as $path => [, $description]) {
            $this->assertNotSame('', trim($description), "{$path}: empty description.");
        }
    }

    /**
     * Every path names a real category.
     *
     * The seeder warns and skips, so a typo or a renamed category is silent
     * in a seeding run: the row is simply never written and the page keeps
     * its templated title forever.
     */
    public function test_every_path_names_a_real_category(): void
    {
        $paths = Category::query()->pluck('path')->all();

        if ($paths === []) {
            $this->markTestSkipped('No taxonomy seeded in this database.');
        }

        foreach (array_keys($this->entries()) as $path) {
            $this->assertContains($path, $paths, "No category at {$path}.");
        }
    }

    public function test_it_writes_the_title_and_description(): void
    {
        DB::statement('TRUNCATE categories RESTART IDENTITY CASCADE');

        $category = Category::factory()->root('smart-tech')->create();

        $this->seed(CategorySeoSeeder::class);

        $category->refresh();

        $this->assertSame(
            'Smart Tech, Power Backup & Security Equipment in Nigeria',
            $category->meta_title,
        );
        $this->assertStringContainsString('UPS, inverters, solar', (string) $category->meta_description);
    }

    /**
     * An empty title in the constant means "the storefront template wins".
     * Writing '' rather than null would make the template lose to an empty
     * string, and the page would have no title at all.
     */
    public function test_an_empty_title_is_stored_as_null(): void
    {
        DB::statement('TRUNCATE categories RESTART IDENTITY CASCADE');

        $department = Category::factory()->root('smart-tech')->create();
        $sub = Category::factory()->childOf($department, 'power-backup')->create();
        $leaf = Category::factory()->childOf($sub, 'voltage-stabilisers')->create();

        $this->seed(CategorySeoSeeder::class);

        $this->assertNull($leaf->refresh()->meta_title);
        $this->assertNotNull($leaf->meta_description);
    }
}
