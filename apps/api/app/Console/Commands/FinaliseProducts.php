<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Content\ContentMinimums;
use App\Content\DescriptionDrafter;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Make imported products presentable and, where they qualify, live and
 * indexable - at scale, without hand-writing each one.
 *
 * Per product it: drafts a description from the structured specs (unique per
 * product, never the supplier's verbatim text), fills a one-line summary and the
 * SEO meta title/description if blank, and - with --index - treats the generated
 * copy as accepted and turns indexing on for products that clear a pragmatic
 * import bar: a category, a working (derivative-backed) image, at least three
 * specifications, and a 120-word generated description.
 *
 * That bar is deliberately NOT the full manual-curation bar (which also demands
 * an "in the box" list and a stated warranty Icecat does not provide): it is the
 * bar at which a page carries real, differentiating, non-duplicate content and
 * is worth indexing. Everything it skips is reported so the gaps are visible.
 *
 *   php artisan catalogue:finalise-products --brand=hp                    # dry run
 *   php artisan catalogue:finalise-products --brand=hp --apply            # content only
 *   php artisan catalogue:finalise-products --brand=hp --apply --index    # + publish/index ready ones
 */
final class FinaliseProducts extends Command
{
    protected $signature = 'catalogue:finalise-products
        {--brand= : Restrict to one brand key (e.g. hp).}
        {--apply : Write the generated description, summary and meta.}
        {--index : Accept the generated copy and publish + index products that qualify.}
        {--chunk=200 : Products per pass.}';

    protected $description = 'Draft descriptions, fill meta, and publish/index imported products that qualify';

    private int $described = 0;

    private int $metaFilled = 0;

    private int $indexed = 0;

    /** @var array<string, int> */
    private array $blocked = [];

    public function handle(DescriptionDrafter $drafter, ContentMinimums $minimums): int
    {
        $apply = (bool) $this->option('apply');
        $index = (bool) $this->option('index');

        $brandId = null;
        if ($this->option('brand') !== null) {
            $brandId = Brand::query()->where('slug', Str::slug((string) $this->option('brand')))->value('id');
            if ($brandId === null) {
                $this->error('Unknown brand.');

                return self::INVALID;
            }
        }

        $seen = 0;

        Product::query()
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->with(['attributeValues.attribute', 'brand', 'primaryCategory'])
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($products) use ($drafter, $minimums, $apply, $index, &$seen): void {
                foreach ($products as $product) {
                    $seen++;
                    $this->finalise($product, $drafter, $minimums, $apply, $index);
                }
            });

        $this->newLine();
        $this->table(['Outcome', 'Products'], [
            [$apply ? 'Descriptions written' : 'Would describe', $this->described],
            [$apply ? 'Meta filled' : 'Would fill meta', $this->metaFilled],
            [$index ? 'Published + indexed' : 'Would index', $this->indexed],
            ['Seen', $seen],
        ]);

        if ($index && $this->blocked !== []) {
            arsort($this->blocked);
            $this->newLine();
            $this->warn('Not indexed - why (fix the data, then re-run):');
            $rows = [];
            foreach ($this->blocked as $reason => $count) {
                $rows[] = [$reason, $count];
            }
            $this->table(['Blocker', 'Products'], $rows);
        }

        if (! $apply) {
            $this->comment('Dry run. Add --apply (and --index to publish qualifying products).');
        }

        return self::SUCCESS;
    }

    private function finalise(Product $product, DescriptionDrafter $drafter, ContentMinimums $minimums, bool $apply, bool $index): void
    {
        // 1. Description from specs (the drafter refuses when there is too little
        //    structured data, which is correct - that product needs data, not copy).
        if (trim(strip_tags((string) $product->description_html)) === '') {
            $body = $apply ? $drafter->draft($product) : $drafter->compose($product);
            if ($body !== null) {
                $this->described++;
            }
        }

        // 2. Summary + meta, only when blank (never clobber human copy).
        $short = $this->summaryLine($product);
        $changed = false;

        if ($this->blank($product->short_description) && $short !== null) {
            $product->short_description = $short;
            $changed = true;
        }
        if ($this->blank($product->meta_title)) {
            $product->meta_title = $this->truncateWords($product->name, 60);
            $changed = true;
        }
        if ($this->blank($product->meta_description)) {
            $metaDesc = $short ?? Str::limit(trim(strip_tags((string) $product->description_html)), 155, '');
            if ($metaDesc !== '') {
                $product->meta_description = Str::limit($metaDesc, 155, '');
                $changed = true;
            }
        }

        if ($changed) {
            $this->metaFilled++;
            if ($apply) {
                $product->save();
            }
        }

        if (! $index) {
            return;
        }

        // 3. Accept the generated copy (the admin running --index is the person
        //    approving it) and index/publish when the import bar is cleared.
        $product->refresh()->loadMissing(['attributeValues']);

        if ($product->description_generated_at !== null && ! $product->descriptionIsApproved()) {
            if ($apply) {
                $product->forceFill(['description_approved_at' => now()])->save();
            }
        }

        $blocker = $this->importBlocker($product, $minimums);

        if ($blocker !== null) {
            $this->blocked[$blocker] = ($this->blocked[$blocker] ?? 0) + 1;

            return;
        }

        $this->indexed++;

        if ($apply) {
            $product->forceFill([
                'status' => 'active',
                'is_indexable' => true,
                'published_at' => $product->published_at ?? now(),
            ])->save();
        }
    }

    /**
     * The first reason this product is not import-ready, or null when it is.
     * A pragmatic bar: category, a renderable image, >=3 specs, a >=120-word
     * description (generated copy counts once accepted via --index).
     */
    private function importBlocker(Product $product, ContentMinimums $minimums): ?string
    {
        if ($product->primary_category_id === null) {
            return 'no category';
        }

        if (! $product->media()->whereHas('derivatives')->exists()) {
            return 'no usable image (missing derivatives)';
        }

        if ($product->attributeValues->count() < ContentMinimums::PRODUCT_MIN_ATTRIBUTES) {
            return 'fewer than '.ContentMinimums::PRODUCT_MIN_ATTRIBUTES.' specifications';
        }

        if ($minimums->words($product->description_html) < ContentMinimums::PRODUCT_WORDS) {
            return 'description under '.ContentMinimums::PRODUCT_WORDS.' words';
        }

        return null;
    }

    /** A one-line summary built from brand, category and the top key specs. */
    private function summaryLine(Product $product): ?string
    {
        $brand = $product->brand?->name ?? $product->brand_name_cached;
        $category = $product->primaryCategory?->name;

        $specs = $product->attributeValues
            ->filter(fn ($v): bool => $v->attribute?->is_key_spec === true && trim($v->displayValue()) !== '')
            ->sortByDesc(fn ($v): int => (int) ($v->attribute->display_order ?? 0))
            ->take(3)
            ->map(fn ($v): string => $v->displayValue())
            ->implode(', ');

        $lead = trim(($brand ? $brand.' ' : '').($category ? rtrim($category, 's') : ''));
        $lead = $lead !== '' ? $lead : $product->name;

        $line = $specs !== '' ? $lead.' — '.$specs.'.' : $lead.'.';
        $line .= ' Available from OfficeLand with nationwide delivery; request a quote for pricing.';

        return Str::limit($line, 320, '');
    }

    private function blank(?string $value): bool
    {
        return trim(strip_tags((string) $value)) === '';
    }

    /** Trim to a length without cutting a word in half. */
    private function truncateWords(string $text, int $max): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false ? mb_substr($cut, 0, $lastSpace) : $cut, ' -–—,');
    }
}
