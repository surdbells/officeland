<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\QuoteBasket;
use App\Models\QuoteBasketItem;
use App\Quotes\BasketService;
use App\Quotes\QuantityRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The basket as the storefront needs it.
 *
 * ── The quantity rules travel with each line ─────────────────────────────
 * The stepper on the basket page has to know the minimum and the step to
 * render a usable control, and it must agree with what the server will accept.
 * Sending the rules alongside the line is what keeps those in step: the client
 * cannot compute them from anything it already has, and guessing produces a
 * control that offers quantities the server then silently changes.
 *
 * ── No prices, and no total ──────────────────────────────────────────────
 * D-002. A "total" of any kind would be the one number a buyer looks for, and
 * there isn't one until sales prices it.
 *
 * @mixin QuoteBasket
 */
final class BasketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // An absent basket is an EMPTY basket, not an error. A first-time
        // visitor and a visitor who emptied theirs are the same state, and the
        // storefront should not need two code paths for them.
        if ($this->resource === null) {
            return [
                'uuid' => null,
                'items' => [],
                'line_count' => 0,
                'unit_count' => 0,
                'max_lines' => BasketService::MAX_LINES,
            ];
        }

        $items = $this->items;

        return [
            'uuid' => $this->uuid,
            'items' => $items->map(fn (QuoteBasketItem $item): array => $this->line($item))->values()->all(),
            'line_count' => $items->count(),
            'unit_count' => (int) $items->sum('qty'),
            'max_lines' => BasketService::MAX_LINES,
            'expires_at' => $this->expires_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function line(QuoteBasketItem $item): array
    {
        $product = $item->product;
        $rules = QuantityRules::forProduct($product);

        return [
            // The line's own id, which every mutation addresses. Scoped to the
            // basket server-side, so exposing it grants nothing.
            'id' => (string) $item->id,

            'qty' => $item->qty,
            'note' => $item->customer_note,

            'min_order_qty' => $rules->minimum,
            'qty_step' => $rules->step,
            'quantity_note' => $rules->describe(),

            'is_free_text' => $item->isFreeText(),

            /*
             * The display name resolves the same way the quote snapshot will:
             * the product when there is one, the free text otherwise. A line
             * whose product was deleted after it was added keeps its
             * description rather than becoming a bare quantity.
             */
            'name' => $product instanceof Product
                ? $product->name
                : ($item->free_text_description ?? 'Unspecified item'),
            'url' => $product?->url(),
            'sku' => $product?->sku,
            'mpn' => $product?->mpn,
            'brand' => $product?->brand_name_cached,
            'availability' => $product?->availability,
            'lead_time_days' => $product?->lead_time_days,
        ];
    }
}
