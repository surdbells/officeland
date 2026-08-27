<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Content\ContentMinimums;
use App\Content\ContentValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What still needs writing, and in what order (§12f).
 *
 * ── Why the order is the feature ─────────────────────────────────────────
 * A list of 15,000 products missing descriptions is not a work queue, it is a
 * reason to give up. The only version of this screen that gets used is one
 * that answers "what should I write this morning" in its first ten rows - so
 * the ordering (ContentValue) is doing more work here than the list itself.
 *
 * ── Categories before products ───────────────────────────────────────────
 * 124 category intros are a week's work and unlock indexability for the pages
 * that the whole taxonomy hangs off; 15,000 product descriptions are a
 * quarter's. Doing the cheap structural work first is not a preference, it is
 * the difference between launching with a coherent site and launching with
 * 15,000 orphaned leaves.
 *
 * ── What this screen cannot tell you ─────────────────────────────────────
 * Whether the writing is any good. It counts words and checks for a warranty
 * line. A page can pass every check here and still be worthless, which is why
 * approval is a person's act and not a threshold.
 */
final class ContentReadiness extends Page
{
    protected string $view = 'filament.pages.content-readiness';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $title = 'Content readiness';

    protected static ?string $navigationLabel = 'Content readiness';

    protected static ?int $navigationSort = 80;

    /** How many rows of each kind to show. Beyond this it stops being a queue. */
    private const ROWS = 25;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role->managesCatalogue();
    }

    /**
     * Counts for the summary strip.
     *
     * @return array<string, int>
     */
    public function getTotals(): array
    {
        $minimums = app(ContentMinimums::class);

        $categoriesShort = Category::query()
            ->get()
            ->filter(fn (Category $c): bool => ! $minimums->category($c)->isReady())
            ->count();

        return [
            'categories_total' => Category::query()->count(),
            'categories_short' => $categoriesShort,
            'products_total' => Product::query()->count(),
            'products_awaiting_approval' => Product::query()
                ->whereNotNull('description_generated_at')
                ->where(function ($q): void {
                    $q->whereNull('description_approved_at')
                        ->orWhereColumn('description_approved_at', '<', 'description_generated_at');
                })
                ->count(),
            'products_flagged' => Product::query()->whereNotNull('description_flagged_at')->count(),
            'products_indexable' => Product::query()->where('is_indexable', true)->count(),
        ];
    }

    /**
     * Categories short of an intro, most valuable first.
     *
     * @return array<int, array{category: Category, readiness: string, score: float, ready: bool}>
     */
    public function getCategoryQueue(): array
    {
        $minimums = app(ContentMinimums::class);
        $scores = app(ContentValue::class)->forCategories();

        return Category::query()
            ->get()
            ->map(fn (Category $category): array => [
                'category' => $category,
                'readiness' => $minimums->category($category)->summary(),
                'score' => $scores[(int) $category->id] ?? 0.0,
                'ready' => $minimums->category($category)->isReady(),
            ])
            ->filter(fn (array $row): bool => ! $row['ready'])
            ->sortByDesc('score')
            ->take(self::ROWS)
            ->values()
            ->all();
    }

    /**
     * Products blocked from indexing, most valuable first.
     *
     * @return array<int, array{product: Product, readiness: string, score: float}>
     */
    public function getProductQueue(): array
    {
        $minimums = app(ContentMinimums::class);
        $value = app(ContentValue::class);
        $scores = $value->forCategories();

        /*
         * Only products that are ACTIVE and not yet indexable. A draft product
         * is not withheld from Google by its description - it is withheld by
         * being a draft - so listing it here would pad the queue with work
         * that changes nothing.
         */
        return Product::query()
            ->where('status', 'active')
            ->where('is_indexable', false)
            ->withCount('attributeValues')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (Product $product): array => [
                'product' => $product,
                'readiness' => $minimums->product($product)->summary(),
                'score' => $value->forProduct($product, $scores),
            ])
            ->sortByDesc('score')
            ->take(self::ROWS)
            ->values()
            ->all();
    }

    /**
     * Descriptions the similarity gate flagged, worst first (§12b).
     *
     * @return list<Product>
     */
    public function getFlagged(): array
    {
        /** @var list<Product> $flagged */
        $flagged = Product::query()
            ->whereNotNull('description_flagged_at')
            ->with('descriptionSimilarTo')
            ->orderByDesc('description_similarity')
            ->limit(self::ROWS)
            ->get()
            ->all();

        return $flagged;
    }

    public function getSubheading(): string
    {
        return 'Ordered by internal search demand, which is a stand-in for keyword value until '.
            'Search Console has data. Categories first - they unlock whole subtrees.';
    }
}
