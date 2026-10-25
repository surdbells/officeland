<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Attribute;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a product's specifications as structured attribute values.
 *
 * The Icecat path builds its spec table straight from the data sheet
 * (IcecatIngestor); the supplier-site importers have real specs of their own -
 * a stapler's capacity, a panel's dimensions, a chair's mechanism - that were
 * being thrown away. This is the one shared way for those importers to record
 * them, so a WiWU hub and an Icecat laptop end up with the same shape of spec
 * table, and so the DescriptionDrafter (which writes from attributes) and the
 * content-readiness floor (which needs at least three) work for both.
 *
 * Attributes are keyed by a slug of their name, so the same spec across products
 * ("Material", "Capacity") is one Attribute with many values - which is what
 * makes it a real filter facet and what lets DescriptionDrafter tell a product
 * apart from its siblings. Idempotent: re-running replaces this product's values
 * for the given specs rather than accreting duplicates.
 */
final class ProductSpecs
{
    /**
     * Set a product's specifications from a [name => value] map (value may carry
     * its own unit, e.g. "10 sheets"). Blank names/values are skipped. Returns
     * the number of specs written.
     *
     * @param  array<string, scalar|null>  $specs
     */
    public static function set(Product $product, array $specs): int
    {
        $clean = [];
        foreach ($specs as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name === '' || $value === '' || Str::lower($value) === 'n/a') {
                continue;
            }
            // First writer of a given name wins (dedupe case/space variants).
            $key = Str::slug($name);
            if ($key !== '' && ! isset($clean[$key])) {
                $clean[$key] = ['name' => $name, 'value' => $value];
            }
        }

        if ($clean === []) {
            return 0;
        }

        $written = 0;

        DB::transaction(function () use ($product, $clean, &$written): void {
            $order = 0;
            foreach ($clean as $code => $spec) {
                $attribute = Attribute::query()->firstOrCreate(
                    ['code' => 'spec-'.$code],
                    [
                        'uuid' => (string) Str::uuid(),
                        'name' => $spec['name'],
                        'data_type' => self::numericOf($spec['value']) !== null ? 'numeric' : 'string',
                        'is_filterable' => true,
                        'display_order' => $order,
                    ],
                );

                $product->attributeValues()->updateOrCreate(
                    ['attribute_id' => $attribute->id],
                    [
                        'value_raw' => $spec['value'],
                        'value_presentation' => $spec['value'],
                        'value_numeric' => self::numericOf($spec['value']),
                        'sort_order' => $order,
                    ],
                );

                $order++;
                $written++;
            }

            // search_text_specs feeds the weight-C term of the search vector and
            // is not maintained automatically; rebuild it from current values.
            $product->load('attributeValues.attribute');
            $text = $product->attributeValues
                ->map(fn ($v): string => ($v->attribute?->name ?? '').' '.$v->displayValue())
                ->implode(' ');
            $product->forceFill(['search_text_specs' => Str::limit($text, 8000, '')])->save();
        });

        return $written;
    }

    /**
     * A leading numeric value for range facets, or null. Capped to fit
     * DECIMAL(16,4): a larger number is a code/EAN, not a measurement.
     */
    private static function numericOf(string $value): ?float
    {
        if (! preg_match('/-?\d+(?:[.,]\d+)?/', $value, $m)) {
            return null;
        }

        $number = (float) str_replace(',', '.', $m[0]);

        return abs($number) < 1_000_000_000_000 ? $number : null;
    }
}
