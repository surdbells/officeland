<?php

declare(strict_types=1);

namespace App\Quotes;

use App\Models\Brand;
use App\Models\Quote;
use App\Models\QuoteBasket;
use App\Models\QuoteBasketItem;
use App\Models\QuoteItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a basket into a submitted quote (§9d, §9f).
 *
 * ── One transaction, and why ─────────────────────────────────────────────
 * The reference, the quote, its lines, the event and closing the basket all
 * commit together or not at all. A quote with no lines, or a reference issued
 * to a quote that never saved, is worse than a failed submission: the customer
 * gets a confirmation for something sales cannot see.
 *
 * ── Everything is snapshotted ────────────────────────────────────────────
 * Names, SKUs, specifications and URLs are copied onto the quote rather than
 * referenced. A quote records what the buyer asked for at the moment they
 * asked; if it rendered live, renaming a product would retroactively change
 * what a customer is on record as having requested, and the email they hold
 * would no longer match the quote we hold.
 */
final class QuoteSubmitter
{
    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly BasketSession $session,
    ) {}

    /**
     * @param  array{email: string, phone: string, message?: string|null}  $contact
     * @param  array<string, string|null>  $attribution
     */
    public function submit(QuoteBasket $basket, array $contact, array $attribution = []): Quote
    {
        $items = $basket->items()->with(['product.brand', 'variant'])->get();

        if ($items->isEmpty()) {
            throw new RuntimeException('A quote request needs at least one line.');
        }

        return DB::transaction(function () use ($basket, $items, $contact, $attribution): Quote {
            $quote = Quote::query()->create([
                'uuid' => (string) Str::uuid(),
                'reference' => $this->references->next(),
                'email' => $contact['email'],
                'phone' => $contact['phone'],
                'message' => $contact['message'] ?? null,
                'status' => 'new',
                'source' => 'web',
                'utm_source' => $attribution['utm_source'] ?? null,
                'utm_medium' => $attribution['utm_medium'] ?? null,
                'utm_campaign' => $attribution['utm_campaign'] ?? null,
                'utm_term' => $attribution['utm_term'] ?? null,
                'utm_content' => $attribution['utm_content'] ?? null,
                'referrer' => $attribution['referrer'] ?? null,
                'landing_page' => $attribution['landing_page'] ?? null,
                /*
                 * The IP is HASHED, never stored raw. It exists for abuse
                 * investigation — "did these fifty submissions come from one
                 * place" — which a hash answers just as well, and NDPA
                 * compliance is easier to argue for a hash than for an address
                 * we had no need to keep.
                 */
                'ip_hash' => $attribution['ip_hash'] ?? null,
                'user_agent' => $attribution['user_agent'] ?? null,
                'submitted_at' => now(),
            ]);

            foreach ($items->values() as $index => $item) {
                /** @var QuoteBasketItem $item */
                $this->snapshot($quote->id, $item, $index);
            }

            $quote->events()->create([
                'type' => 'submitted',
                'actor_label' => 'customer',
                'payload' => [
                    'lines' => $items->count(),
                    'units' => $items->sum('qty'),
                ],
                'trace_id' => request()->attributes->get('trace_id'),
            ]);

            $this->session->close($basket);

            return $quote;
        });
    }

    private function snapshot(int $quoteId, QuoteBasketItem $item, int $sortOrder): void
    {
        $product = $item->product;

        $quoteItem = [
            'quote_id' => $quoteId,
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'qty' => $item->qty,
            'customer_note' => $item->customer_note,
            'sort_order' => $sortOrder,
        ];

        if ($product === null) {
            /*
             * A free-text line, or a line whose product has since been
             * deleted. The description IS the product name for a salesperson
             * reading the email — leaving it blank would produce a quantity
             * with nothing attached to it.
             */
            $quoteItem['product_name_snapshot'] = (string) ($item->free_text_description ?? 'Unspecified item');
            $quoteItem['free_text_description'] = $item->free_text_description;
        } else {
            $quoteItem['product_name_snapshot'] = (string) $product->name;
            $quoteItem['sku_snapshot'] = $product->sku;
            $quoteItem['mpn_snapshot'] = $product->mpn;
            /*
             * The cached column first: it is maintained precisely so a
             * snapshot does not depend on a relation being loaded, and a
             * product may legitimately have no brand at all.
             */
            $brandName = $product->brand_name_cached;

            if ($brandName === null || $brandName === '') {
                $brand = $product->relationLoaded('brand') ? $product->getRelation('brand') : null;
                $brandName = $brand instanceof Brand ? $brand->name : null;
            }

            $quoteItem['brand_name_snapshot'] = $brandName;
            $quoteItem['spec_snapshot'] = $product->spec_summary;
            $quoteItem['product_url_snapshot'] = $product->url();
        }

        QuoteItem::query()->create($quoteItem);
    }
}
