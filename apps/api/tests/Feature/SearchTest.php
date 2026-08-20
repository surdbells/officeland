<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SearchQueryLog;
use App\Models\SearchSynonym;
use App\Search\PostgresSearchEngine;
use App\Search\SearchEngine;
use App\Search\SearchQuery;
use App\Search\SynonymExpander;
use Database\Seeders\SearchSynonymSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SearchTest extends TestCase
{
    private SearchEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('product_attribute_values')->delete();
        DB::table('product_category')->delete();
        DB::table('products')->delete();
        DB::table('attributes')->delete();
        DB::table('attribute_groups')->delete();
        DB::table('categories')->delete();
        DB::table('brands')->delete();
        DB::table('search_queries_log')->delete();

        // Synonyms are reset and re-seeded per test. Without this a synonym
        // created by one test survives into the next AND across runs, which
        // is exactly how "doohickey" silently became a synonym of "widget"
        // and made an assertion pass for the wrong reason.
        DB::table('search_synonyms')->delete();
        $this->seed(SearchSynonymSeeder::class);

        Cache::flush();
        SynonymExpander::forget();

        $this->engine = app(SearchEngine::class);
    }

    protected function tearDown(): void
    {
        DB::table('product_attribute_values')->delete();
        DB::table('product_category')->delete();
        DB::table('products')->delete();
        DB::table('attributes')->delete();
        DB::table('attribute_groups')->delete();
        DB::table('categories')->delete();
        DB::table('brands')->delete();
        DB::table('search_queries_log')->delete();
        DB::table('search_synonyms')->delete();

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function product(string $name, array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'search_text_specs' => $overrides['search_text_specs'] ?? '',
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // The interface itself (section 16)
    // -----------------------------------------------------------------

    public function test_the_engine_is_resolved_through_the_interface(): void
    {
        // Section 16 requires the swap to Meilisearch to touch no callers.
        $this->assertInstanceOf(PostgresSearchEngine::class, app(SearchEngine::class));
    }

    // -----------------------------------------------------------------
    // Ranking
    // -----------------------------------------------------------------

    public function test_full_text_search_finds_a_product_by_name(): void
    {
        $this->product('HP LaserJet Toner Cartridge');
        $this->product('Ergonomic Mesh Task Chair');

        $result = $this->engine->search(new SearchQuery(term: 'toner'));

        $this->assertSame(1, $result->total);
        $this->assertStringContainsString('Toner', $result->items->first()->name);
    }

    /**
     * Section 16: "exact SKU/MPN match wins outright". Not a tie-break — a
     * short-circuit. Someone typing a model code has told you exactly what
     * they want.
     */
    public function test_an_exact_mpn_match_returns_that_product_alone(): void
    {
        $wanted = $this->product('HP 128A Black Toner', ['mpn' => 'CE320A']);

        // Decoys that a relevance ranking would happily mix in.
        $this->product('CE320A compatible toner refill kit');
        $this->product('Toner for CE320A printers');

        $result = $this->engine->search(new SearchQuery(term: 'CE320A'));

        $this->assertSame(1, $result->total);
        $this->assertSame($wanted->id, $result->items->first()->id);
    }

    public function test_an_exact_sku_match_wins_outright(): void
    {
        $wanted = $this->product('Executive Chair', ['sku' => 'OL-CHAIR-001']);
        $this->product('Chair spare parts for OL-CHAIR-001');

        $result = $this->engine->search(new SearchQuery(term: 'OL-CHAIR-001'));

        $this->assertSame(1, $result->total);
        $this->assertSame($wanted->id, $result->items->first()->id);
    }

    /** Buyers type model codes inconsistently; the match must survive that. */
    public function test_an_identifier_match_ignores_spacing_and_hyphens(): void
    {
        $wanted = $this->product('HP Toner', ['mpn' => 'CE-320-A']);

        $result = $this->engine->search(new SearchQuery(term: 'ce320a'));

        $this->assertSame(1, $result->total);
        $this->assertSame($wanted->id, $result->items->first()->id);
    }

    /**
     * Two products sharing an MPN is a data problem. Silently picking one
     * would hide it, so the short-circuit declines and normal ranking runs.
     */
    public function test_an_ambiguous_identifier_does_not_short_circuit(): void
    {
        $hp = Brand::factory()->create(['name' => 'HP']);
        $generic = Brand::factory()->create(['name' => 'Generic']);

        $this->product('HP Toner', ['mpn' => 'XY999', 'brand_id' => $hp->id]);
        $this->product('Generic Toner', ['mpn' => 'XY999', 'brand_id' => $generic->id]);

        $result = $this->engine->search(new SearchQuery(term: 'XY999'));

        $this->assertNotSame(1, $result->total);
    }

    public function test_in_stock_products_outrank_equally_relevant_ones(): void
    {
        $this->product('Toner Cartridge Alpha', ['availability' => 'discontinued']);
        $inStock = $this->product('Toner Cartridge Beta', ['availability' => 'in_stock']);

        $result = $this->engine->search(new SearchQuery(term: 'toner cartridge'));

        $this->assertSame($inStock->id, $result->items->first()->id);
    }

    public function test_draft_products_are_never_returned(): void
    {
        $this->product('Draft Toner', ['status' => 'draft']);

        $this->assertSame(0, $this->engine->search(new SearchQuery(term: 'toner'))->total);
    }

    // -----------------------------------------------------------------
    // Synonyms (section 16)
    // -----------------------------------------------------------------

    public function test_a_nigerian_english_term_finds_the_catalogue_term(): void
    {
        $this->product('Blue Ballpoint Pen Box of 50');

        // "biro" is what a Nigerian buyer types; the catalogue says "pen".
        $result = $this->engine->search(new SearchQuery(term: 'biro'));

        $this->assertSame(1, $result->total);
    }

    public function test_synonyms_work_in_both_directions(): void
    {
        $this->product('USB Flash Drive 64GB');

        $this->assertSame(1, $this->engine->search(new SearchQuery(term: 'pendrive'))->total);
        $this->assertSame(1, $this->engine->search(new SearchQuery(term: 'flash drive'))->total);
    }

    /**
     * The commercially valuable case: someone reading CE320A off a spent
     * cartridge and someone who knows it as 128A are the same customer.
     */
    public function test_a_model_code_synonym_finds_the_product(): void
    {
        $this->product('HP 128A Black LaserJet Toner');

        $result = $this->engine->search(new SearchQuery(term: 'ce320a'));

        $this->assertGreaterThanOrEqual(1, $result->total);
    }

    /** Typo entries are one-way: the misspelling finds the correct term. */
    public function test_a_typo_synonym_is_one_way(): void
    {
        $expander = app(SynonymExpander::class);

        $this->assertContains('stationery', $expander->flatten('stationary'));
        $this->assertNotContains('stationary', $expander->flatten('stationery'));
    }

    public function test_editing_a_synonym_takes_effect_immediately(): void
    {
        $this->product('Widget Gadget');

        $this->assertSame(0, $this->engine->search(new SearchQuery(term: 'doohickey'))->total);

        SearchSynonym::create([
            'term' => 'doohickey', 'synonym' => 'widget',
            'type' => 'usage', 'bidirectional' => true, 'is_active' => true,
        ]);

        // The expander caches the table; a save that does not take effect
        // gets reported as "search is broken".
        $this->assertSame(1, $this->engine->search(new SearchQuery(term: 'doohickey'))->total);
    }

    // -----------------------------------------------------------------
    // Typo tolerance
    // -----------------------------------------------------------------

    public function test_a_mistyped_term_still_finds_the_product(): void
    {
        $this->product('Ergonomic Mesh Office Chair');

        $result = $this->engine->search(new SearchQuery(term: 'ergonomik'));

        $this->assertSame(1, $result->total);
        // Reported so the storefront can say "showing results for something
        // similar" rather than implying the match was exact.
        $this->assertTrue($result->usedFallback);
    }

    public function test_an_exact_match_does_not_use_the_fallback(): void
    {
        $this->product('Ergonomic Mesh Office Chair');

        $this->assertFalse($this->engine->search(new SearchQuery(term: 'ergonomic'))->usedFallback);
    }

    // -----------------------------------------------------------------
    // Facets (section 16 / 23.2)
    // -----------------------------------------------------------------

    private function attributeWithValues(string $code, string $name, array $productValues): Attribute
    {
        $group = AttributeGroup::create(['code' => 'general', 'name' => 'General', 'display_order' => 1]);

        $attribute = Attribute::create([
            'code' => $code, 'name' => $name, 'group_id' => $group->id,
            'data_type' => 'string', 'is_filterable' => true, 'display_order' => 10,
        ]);

        foreach ($productValues as $productId => $value) {
            DB::table('product_attribute_values')->insert([
                'product_id' => $productId,
                'attribute_id' => $attribute->id,
                'value_raw' => $value,
                'value_presentation' => ucfirst($value),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $attribute;
    }

    public function test_facets_carry_accurate_counts(): void
    {
        $a = $this->product('Chair One');
        $b = $this->product('Chair Two');
        $c = $this->product('Chair Three');

        $this->attributeWithValues('colour', 'Colour', [
            $a->id => 'black', $b->id => 'black', $c->id => 'grey',
        ]);

        $result = $this->engine->search(new SearchQuery(term: 'chair'));

        $colour = collect($result->facets)->firstWhere('code', 'colour');
        $this->assertNotNull($colour);

        $counts = collect($colour->values)->pluck('count', 'value')->all();
        $this->assertSame(2, $counts['black']);
        $this->assertSame(1, $counts['grey']);
    }

    /**
     * Section 16: "Facets must not offer a combination that returns zero
     * results." Counting only over rows that exist is what guarantees it.
     */
    public function test_facets_never_offer_a_zero_result_value(): void
    {
        $a = $this->product('Chair One');
        $this->attributeWithValues('colour', 'Colour', [$a->id => 'black']);

        $result = $this->engine->search(new SearchQuery(term: 'chair'));

        foreach ($result->facets as $group) {
            foreach ($group->values as $value) {
                $this->assertGreaterThan(0, $value['count'], "{$group->code}={$value['value']} would be a dead end");
            }
        }
    }

    /**
     * A facet is counted with every OTHER filter applied but not its own, so
     * a buyer can still see the other colours after choosing one.
     */
    public function test_selecting_a_value_keeps_its_siblings_visible(): void
    {
        $a = $this->product('Chair One');
        $b = $this->product('Chair Two');

        $this->attributeWithValues('colour', 'Colour', [$a->id => 'black', $b->id => 'grey']);

        $result = $this->engine->search(new SearchQuery(term: 'chair', filters: ['colour' => ['black']]));

        $colour = collect($result->facets)->firstWhere('code', 'colour');
        $values = collect($colour->values)->pluck('value')->all();

        $this->assertContains('black', $values);
        $this->assertContains('grey', $values, 'Sibling values must stay selectable, or the filter is a one-way door.');
    }

    public function test_a_filter_narrows_the_results(): void
    {
        $a = $this->product('Chair One');
        $b = $this->product('Chair Two');

        $this->attributeWithValues('colour', 'Colour', [$a->id => 'black', $b->id => 'grey']);

        $result = $this->engine->search(new SearchQuery(term: 'chair', filters: ['colour' => ['black']]));

        $this->assertSame(1, $result->total);
        $this->assertSame($a->id, $result->items->first()->id);
    }

    // -----------------------------------------------------------------
    // Typeahead — the latency gate
    // -----------------------------------------------------------------

    public function test_suggestions_are_grouped(): void
    {
        Category::factory()->root('office-furniture')->create();
        Brand::factory()->create(['name' => 'Officemate']);
        $this->product('Office Chair Deluxe');

        $set = $this->engine->suggest('office');

        $this->assertNotEmpty($set->products);
        $this->assertNotEmpty($set->categories);
        $this->assertNotEmpty($set->brands);
    }

    public function test_a_single_character_returns_nothing(): void
    {
        $this->product('Office Chair');

        // Suggesting on one character means suggesting the whole catalogue.
        $this->assertTrue($this->engine->suggest('o')->isEmpty());
    }

    /**
     * Section 16: typeahead p95 <= 50ms. Measured over 20 runs against a
     * populated catalogue; the p95 is what a user on a slow keystroke feels.
     */
    public function test_typeahead_meets_the_p95_latency_budget(): void
    {
        Product::factory()->count(300)->create();

        $timings = [];

        foreach (['off', 'chair', 'ton', 'hp', 'a4'] as $term) {
            for ($i = 0; $i < 4; $i++) {
                $start = hrtime(true);
                $this->engine->suggest($term);
                $timings[] = (hrtime(true) - $start) / 1e6;
            }
        }

        sort($timings);
        $p95 = $timings[(int) floor(count($timings) * 0.95) - 1];

        $this->assertLessThan(
            50.0,
            $p95,
            sprintf('Typeahead p95 was %.1fms against a 50ms budget.', $p95),
        );
    }

    // -----------------------------------------------------------------
    // Query logging (section 14.10)
    // -----------------------------------------------------------------

    public function test_a_search_is_logged_with_its_result_count(): void
    {
        $this->product('Office Chair');

        $this->getJson('/api/v1/search?q=chair')->assertOk();

        $log = SearchQueryLog::query()->where('query_normalised', 'chair')->firstOrFail();
        $this->assertSame(1, $log->result_count);
    }

    /** Zero-result terms are the merchandising asset, not the error log. */
    public function test_a_zero_result_search_is_logged_as_such(): void
    {
        $this->getJson('/api/v1/search?q=hovercraft')->assertOk();

        $this->assertSame(1, SearchQueryLog::query()->zeroResult()->count());
    }

    public function test_repeated_searches_increment_rather_than_duplicate(): void
    {
        $this->product('Office Chair');

        $this->getJson('/api/v1/search?q=chair')->assertOk();
        $this->getJson('/api/v1/search?q=CHAIR')->assertOk();
        // Percent-encoded: a raw space in a URI is rejected before it reaches
        // the application, so this exercises the normalisation rather than
        // Symfony's URI validation.
        $this->getJson('/api/v1/search?q=%20%20chair%20%20')->assertOk();

        $log = SearchQueryLog::query()->where('query_normalised', 'chair')->firstOrFail();

        $this->assertSame(1, SearchQueryLog::query()->count());
        $this->assertSame(3, $log->occurrences);
    }

    // -----------------------------------------------------------------
    // API surface
    // -----------------------------------------------------------------

    public function test_the_search_endpoint_returns_results_and_facets(): void
    {
        $this->product('Office Chair');

        $this->getJson('/api/v1/search?q=chair')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'meta' => ['total', 'page', 'per_page', 'duration_ms', 'used_fallback'],
                'facets',
            ]);
    }

    public function test_the_suggest_endpoint_returns_grouped_results(): void
    {
        $this->product('Office Chair');

        $this->getJson('/api/v1/search/suggest?q=chair')
            ->assertOk()
            ->assertJsonStructure(['data' => ['products', 'categories', 'brands'], 'meta' => ['duration_ms']]);
    }

    public function test_an_empty_search_is_not_logged(): void
    {
        $this->getJson('/api/v1/search?q=')->assertOk();

        $this->assertSame(0, SearchQueryLog::query()->count());
    }
}
