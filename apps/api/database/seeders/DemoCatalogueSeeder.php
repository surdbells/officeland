<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A full catalogue of DEMO products, so the design can be judged on a site
 * that looks like a shop rather than a wireframe.
 *
 * ── Read this before running it ──────────────────────────────────────────
 * Every product this creates is invented. The names are plausible, the SKUs
 * are not real, the specifications are generic, and nothing here corresponds
 * to stock OfficeLand actually holds. It exists so that listings, facets,
 * brand rows, pagination and the megamenu can be evaluated with realistic
 * volume - which is impossible against ten products spread over 170
 * categories.
 *
 * ── Why every one of them is noindex ─────────────────────────────────────
 * This is the important part, and it is not a limitation of the seeder.
 *
 * §14.7 requires 120 words of ORIGINAL description, three specifications,
 * box contents, warranty and a lead time before a product may be indexed,
 * and D-050 rejects a description that reads 95% the same as another in its
 * family. Those rules exist precisely to stop a catalogue of near-identical
 * machine-written pages reaching Google, because that is the single fastest
 * way to teach a search engine that a domain is thin.
 *
 * Generating 1,190 descriptions here would either fail that floor, or pass it
 * with exactly the content the floor was built to keep out. So these products
 * are created `is_indexable = false` and stay there. They populate the
 * storefront and they never reach the index.
 *
 * Real products get real copy, and then the floor lets them through on their
 * own - as it already does for the 46 categories with written intros.
 *
 * ── Identifiable and reversible ──────────────────────────────────────────
 * Every SKU starts with DEMO-, which makes them obvious in the admin, in any
 * export, and to the cleanup below. Re-running deletes the previous set
 * first, so this is idempotent rather than cumulative.
 *
 *   php artisan db:seed --class=DemoCatalogueSeeder
 *   php artisan demo:purge          (removes them again)
 */
final class DemoCatalogueSeeder extends Seeder
{
    /** Marks every row this seeder owns. */
    public const SKU_PREFIX = 'DEMO-';

    /** The brief was "at least 10 per category"; listings include the subtree. */
    private const PER_LEAF = 10;

    /**
     * Brands per department, chosen because they are what an office supplier
     * in Lagos actually carries in that department.
     *
     * This is the other half of "brands must speak to the category": the
     * megamenu derives its brand rows from products, so a brand only reaches
     * a panel by having stock in it.
     *
     * @var array<string, list<string>>
     */
    private const BRANDS = [
        '/office-furniture/' => ['OfficeLand', 'Ashford', 'Meridian'],
        '/it-products/' => ['HP', 'Dell', 'Lenovo', 'Logitech', 'Kingston'],
        '/smart-tech/' => ['APC', 'Hikvision', 'Anker', 'Logitech', 'Mercury Power'],
        '/printers-consumables/' => ['HP', 'Canon', 'Epson', 'Brother'],
        '/office-supplies/' => ['Bic', 'Faber-Castell', '3M', 'Stabilo'],
        '/paper-notebooks/' => ['Double A', 'Hammermill', 'OfficeLand'],
        '/filing-storage/' => ['Bantex', 'Leitz', 'OfficeLand'],
        '/school-supplies/' => ['Faber-Castell', 'Bic', 'OfficeLand'],
        '/pvc-doors-interiors/' => ['OfficeLand'],
    ];

    /**
     * Descriptors, combined with the leaf category name to make a product
     * name that reads like a catalogue line rather than lorem ipsum.
     *
     * @var list<string>
     */
    private const DESCRIPTORS = [
        'Standard', 'Heavy Duty', 'Compact', 'Premium', 'Professional',
        'Executive', 'Commercial', 'Industrial', 'Value', 'Deluxe',
    ];

    /** @var list<string> */
    private const VARIANTS = [
        'Black', 'Grey', 'Blue', 'Silver', 'White',
        'A4', 'A3', 'Large', 'Medium', 'Small',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            // Not a warning. Invented products on the live site would be
            // quoted against, and a customer ordering a DEMO- SKU is a real
            // conversation somebody has to have.
            throw new RuntimeException(
                'DemoCatalogueSeeder refuses to run in production. These products are invented.'
            );
        }

        $this->command->warn('Seeding INVENTED demo products. Every SKU is prefixed DEMO- and every one is noindex.');

        $removed = $this->purge();

        if ($removed > 0) {
            $this->command->info("Removed {$removed} product(s) from a previous run.");
        }

        $brands = $this->brands();
        $leaves = Category::query()->where('depth', 3)->orderBy('path')->get();

        if ($leaves->isEmpty()) {
            $this->command->error('No leaf categories. Run TaxonomySeeder first.');

            return;
        }

        $created = 0;

        foreach ($leaves as $leaf) {
            $department = '/'.explode('/', trim($leaf->path, '/'))[0].'/';
            $pool = self::BRANDS[$department] ?? ['OfficeLand'];

            for ($i = 0; $i < self::PER_LEAF; $i++) {
                $this->product($leaf, $brands[$pool[$i % count($pool)]], $i);
                $created++;
            }
        }

        // The listings read this rather than counting on every request.
        $this->refreshCounts();

        $this->command->info(sprintf(
            '%d demo products across %d leaf categories, %d brands. All noindex.',
            $created,
            $leaves->count(),
            count($brands),
        ));
    }

    /**
     * Deletes the previous run.
     *
     * Force-deleted rather than soft-deleted: a soft-deleted demo product
     * still occupies its slug, so the next run would collide with rows nobody
     * can see.
     */
    private function purge(): int
    {
        $ids = Product::withTrashed()
            ->where('sku', 'like', self::SKU_PREFIX.'%')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        DB::table('product_category')->whereIn('product_id', $ids)->delete();
        DB::table('product_relations')->whereIn('product_id', $ids)->delete();
        DB::table('product_relations')->whereIn('related_product_id', $ids)->delete();

        return Product::withTrashed()->whereIn('id', $ids)->forceDelete();
    }

    /**
     * Brands, created if absent and left alone if present.
     *
     * `is_featured` is not set here. The megamenu no longer reads it - brand
     * rows are derived from what a department actually stocks - and flipping
     * it would only affect surfaces that still do.
     *
     * @return array<string, Brand>
     */
    private function brands(): array
    {
        $names = array_values(array_unique(array_merge(...array_values(self::BRANDS))));
        $brands = [];

        foreach ($names as $order => $name) {
            $brands[$name] = Brand::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $name,
                    'is_indexable' => false,
                    'sort_order' => $order,
                ],
            );
        }

        return $brands;
    }

    private function product(Category $leaf, Brand $brand, int $index): void
    {
        $singular = Str::singular($leaf->name);
        $descriptor = self::DESCRIPTORS[$index % count(self::DESCRIPTORS)];
        $variant = self::VARIANTS[($index * 3) % count(self::VARIANTS)];

        $name = trim("{$brand->name} {$descriptor} {$singular} {$variant}");
        $slug = Str::slug($name).'-'.Str::lower(Str::random(4));

        $product = Product::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $slug,
            'sku' => self::SKU_PREFIX.Str::upper(Str::random(8)),
            'mpn' => Str::upper(Str::random(3)).'-'.random_int(1000, 9999),
            'brand_id' => $brand->id,
            'brand_name_cached' => $brand->name,
            'primary_category_id' => $leaf->id,
            'status' => 'active',
            'availability' => $index % 4 === 0 ? 'made_to_order' : 'in_stock',
            'pricing_mode' => 'quote_only',
            // See the class comment. This is the whole point of the seeder
            // being safe to run: it fills the storefront and indexes nothing.
            'is_indexable' => false,
            'min_order_qty' => 1,
            'qty_step' => 1,
            'lead_time_days' => $index % 4 === 0 ? 21 : 3,
            'short_description' => "{$descriptor} {$singular} supplied and delivered nationwide. Quoted per order.",
            // 'demo' is not an allowed source - the column is constrained to
            // icecat/csv/manual. The DEMO- SKU prefix is what identifies
            // these, and it is checked rather than assumed by demo:purge.
            'source' => 'manual',
            'published_at' => now(),
        ]);

        // Filed against the leaf. Department and subcategory listings show the
        // whole subtree, so one attachment populates all three levels.
        $product->categories()->attach($leaf->id, ['sort_order' => $index]);
    }

    /**
     * Refreshes `product_count_cached` for every category.
     *
     * One statement rather than a loop: 170 categories each counting their own
     * subtree is 170 queries, and this runs inside a seeder that has already
     * written a thousand rows.
     */
    private function refreshCounts(): void
    {
        DB::statement(<<<'SQL'
            UPDATE categories AS c
            SET product_count_cached = COALESCE(counted.total, 0)
            FROM (
                SELECT ancestors.id, COUNT(DISTINCT pc.product_id) AS total
                FROM categories AS ancestors
                LEFT JOIN categories AS descendants
                    ON descendants.path LIKE ancestors.path || '%'
                LEFT JOIN product_category AS pc
                    ON pc.category_id = descendants.id
                LEFT JOIN products AS p
                    ON p.id = pc.product_id AND p.status = 'active' AND p.deleted_at IS NULL
                WHERE p.id IS NOT NULL
                GROUP BY ancestors.id
            ) AS counted
            WHERE c.id = counted.id
        SQL);
    }
}
