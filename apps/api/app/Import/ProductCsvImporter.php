<?php

declare(strict_types=1);

namespace App\Import;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * CSV product import with a dry-run diff (MASTER BUILD PROMPT section 11).
 *
 * The brief is explicit that this "must be genuinely robust, not a toy" — it
 * is how a large catalogue gets corrected over time, and under D-022 (Open
 * Icecat only) it is also the ONLY route for brands Icecat does not cover,
 * Baseus among them.
 *
 * Three properties matter more than features:
 *
 *   Idempotent   Re-running the same file changes nothing. Matching is on
 *                MPN + brand first, then SKU — never on name, which editors
 *                rewrite.
 *   Previewable  A dry run reports exactly what would change, per row and per
 *                field, before anything is written.
 *   Honest       A row that cannot be applied is reported with its line number
 *                and reason. Partial success is normal; silence is not.
 */
final class ProductCsvImporter
{
    /** Columns an editor may supply. Anything else is reported, not ignored. */
    public const COLUMNS = [
        'sku', 'mpn', 'model_code', 'ean', 'name', 'slug', 'brand',
        'category_path', 'short_description', 'description_html',
        'availability', 'lead_time_days', 'min_order_qty', 'qty_step', 'status',
    ];

    private const REQUIRED = ['name'];

    private const AVAILABILITY = ['in_stock', 'limited', 'made_to_order', 'lead_time', 'discontinued'];

    private const STATUS = ['draft', 'active', 'archived'];

    /**
     * Reads the file and reports what would happen. Writes nothing.
     */
    public function preview(string $path): ImportReport
    {
        return $this->process($path, apply: false);
    }

    /**
     * Applies the file. Rows that cannot be applied are skipped and reported;
     * one bad row does not abandon the rest, because a 5,000-row import that
     * aborts on line 3,000 is worse than useless.
     */
    public function apply(string $path): ImportReport
    {
        return $this->process($path, apply: true);
    }

    private function process(string $path, bool $apply): ImportReport
    {
        $report = new ImportReport($apply);

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $report->addError(0, 'Could not open the uploaded file.');

            return $report;
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                $report->addError(0, 'The file is empty.');

                return $report;
            }

            // Excel writes a UTF-8 BOM, which otherwise makes the first column
            // name unmatchable — and produces a baffling "sku column missing"
            // on a file that plainly has one.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? '';
            $header = array_map(static fn ($h): string => Str::of((string) $h)->trim()->lower()->replace(' ', '_')->toString(), $header);

            $unknown = array_diff($header, self::COLUMNS);

            foreach ($unknown as $column) {
                $report->addWarning(1, "Column \"{$column}\" is not recognised and will be ignored.");
            }

            $missingRequired = array_diff(self::REQUIRED, $header);

            foreach ($missingRequired as $column) {
                $report->addError(1, "Required column \"{$column}\" is missing.");
            }

            if ($report->hasErrors()) {
                return $report;
            }

            $line = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $line++;

                // fgetcsv returns [null] for a blank line, never an empty array.
                if ($row === [null]) {
                    continue;
                }

                $data = $this->associate($header, $row);

                if ($data === null) {
                    $report->addError($line, 'Row has a different number of columns than the header.');

                    continue;
                }

                $this->row($data, $line, $apply, $report);
            }
        } finally {
            fclose($handle);
        }

        return $report;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string|null>  $row
     * @return array<string, string>|null
     */
    private function associate(array $header, array $row): ?array
    {
        if (count($header) !== count($row)) {
            return null;
        }

        return array_map(static fn ($v): string => trim((string) $v), array_combine($header, $row));
    }

    /**
     * @param  array<string, string>  $data
     */
    private function row(array $data, int $line, bool $apply, ImportReport $report): void
    {
        if (($data['name'] ?? '') === '') {
            $report->addError($line, 'Name is required.');

            return;
        }

        foreach (['availability' => self::AVAILABILITY, 'status' => self::STATUS] as $field => $allowed) {
            if (($data[$field] ?? '') !== '' && ! in_array($data[$field], $allowed, true)) {
                $report->addError($line, sprintf(
                    '%s "%s" is not valid. Use one of: %s.',
                    ucfirst(str_replace('_', ' ', $field)),
                    $data[$field],
                    implode(', ', $allowed),
                ));

                return;
            }
        }

        $brand = $this->resolveBrand($data['brand'] ?? '', $line, $apply, $report);
        $category = $this->resolveCategory($data['category_path'] ?? '', $line, $report);

        if ($category === false) {
            return;
        }

        // Matching order matters. MPN + brand is the manufacturer's identity
        // and survives renaming; SKU is ours. Name is never a key — editors
        // rewrite names, and matching on them would create duplicates on every
        // title correction, which is exactly the legacy site's problem.
        $existing = $this->match($data, $brand);

        $attributes = $this->attributes($data, $brand, $category);

        if ($existing === null) {
            $report->addCreate($line, $data['name'], $attributes);

            if ($apply) {
                // Optional column: a file need not carry every column.
                $attributes['slug'] = $this->uniqueSlug(
                    ($data['slug'] ?? '') !== '' ? $data['slug'] : Str::slug($data['name'])
                );
                $attributes['brand_name_cached'] = $brand?->name;

                // Nulls are stripped on CREATE so the column defaults apply.
                // An omitted min_order_qty means "use the default of 1", not
                // "insert NULL" — the column is NOT NULL, and passing an
                // explicit null overrides the default rather than falling back
                // to it. On UPDATE nulls already mean "leave this alone".
                Product::create(array_filter($attributes, static fn ($v): bool => $v !== null));
            }

            return;
        }

        $changes = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ((string) $existing->getAttribute($key) !== (string) $value) {
                $changes[$key] = ['from' => $existing->getAttribute($key), 'to' => $value];
            }
        }

        if ($changes === []) {
            $report->addUnchanged($line, $existing->name);

            return;
        }

        $report->addUpdate($line, $existing->name, $changes);

        if ($apply) {
            $existing->fill(array_filter($attributes, static fn ($v): bool => $v !== null));
            if ($brand !== null) {
                $existing->brand_name_cached = $brand->name;
            }
            $existing->save();
        }
    }

    /**
     * @param  array<string, string>  $data
     */
    private function match(array $data, ?Brand $brand): ?Product
    {
        if (($data['mpn'] ?? '') !== '' && $brand !== null) {
            $byMpn = Product::query()
                ->where('mpn', $data['mpn'])
                ->where('brand_id', $brand->id)
                ->first();

            if ($byMpn !== null) {
                return $byMpn;
            }
        }

        if (($data['sku'] ?? '') !== '') {
            return Product::query()->where('sku', $data['sku'])->first();
        }

        return null;
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Brand $brand, ?Category $category): array
    {
        $map = [
            'sku' => $data['sku'] ?? '',
            'mpn' => $data['mpn'] ?? '',
            'model_code' => $data['model_code'] ?? '',
            'ean' => $data['ean'] ?? '',
            'name' => $data['name'],
            'short_description' => $data['short_description'] ?? '',
            'description_html' => $data['description_html'] ?? '',
            'availability' => $data['availability'] ?? '',
            'status' => $data['status'] ?? '',
        ];

        $attributes = [];

        foreach ($map as $key => $value) {
            // An empty cell means "leave alone", not "blank it out". Editors
            // routinely submit partial files to correct one column.
            $attributes[$key] = $value === '' ? null : $value;
        }

        foreach (['lead_time_days', 'min_order_qty', 'qty_step'] as $numeric) {
            $attributes[$numeric] = ($data[$numeric] ?? '') === '' ? null : (int) $data[$numeric];
        }

        $attributes['brand_id'] = $brand?->id;
        $attributes['primary_category_id'] = $category?->id;
        $attributes['source'] = 'csv';

        return $attributes;
    }

    private function resolveBrand(string $name, int $line, bool $apply, ImportReport $report): ?Brand
    {
        if ($name === '') {
            return null;
        }

        $brand = Brand::query()->where('name', $name)->first();

        if ($brand !== null) {
            return $brand;
        }

        // Creating a brand silently would let a typo become a permanent brand
        // hub page with its own URL, so it is reported either way.
        $report->addWarning($line, "Brand \"{$name}\" does not exist and will be created.");

        if (! $apply) {
            return null;
        }

        return Brand::create([
            'name' => $name,
            'slug' => $this->uniqueBrandSlug(Str::slug($name)),
        ]);
    }

    /** @return Category|null|false false means the row is invalid */
    private function resolveCategory(string $path, int $line, ImportReport $report): Category|null|false
    {
        if ($path === '') {
            return null;
        }

        $normalised = '/'.trim($path, '/').'/';
        $category = Category::query()->where('path', $normalised)->first();

        if ($category !== null) {
            return $category;
        }

        // Categories are never auto-created: the tree is a designed artefact
        // with a maximum depth and hand-written copy (docs/TAXONOMY.md).
        $report->addError($line, "Category \"{$normalised}\" does not exist. Create it first — categories are not created by import.");

        return false;
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::limit($base, 75, '');
        $candidate = $slug;
        $suffix = 2;

        while (Product::query()->where('slug', $candidate)->exists()) {
            $candidate = Str::limit($slug, 70, '').'-'.$suffix++;
        }

        return $candidate;
    }

    private function uniqueBrandSlug(string $base): string
    {
        $candidate = $base;
        $suffix = 2;

        while (Brand::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
