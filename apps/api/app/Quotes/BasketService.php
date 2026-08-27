<?php

declare(strict_types=1);

namespace App\Quotes;

use App\Models\Product;
use App\Models\QuoteBasket;
use App\Models\QuoteBasketItem;
use RuntimeException;

/**
 * Every basket mutation, in one place (§9a).
 *
 * The controller decides what the caller asked for; this decides what is
 * allowed. Splitting it that way is what keeps the quantity rules and the
 * caps in a single testable object rather than spread across request handlers.
 */
final class BasketService
{
    /**
     * A basket is a requisition list, not a catalogue dump.
     *
     * Bounded because the whole basket ends up in an email a person reads, and
     * because an unbounded basket is a cheap way to make every subsequent
     * request to this endpoint expensive.
     */
    public const MAX_LINES = 100;

    /** Free text is a product description, not a message; the message field is separate. */
    public const MAX_FREE_TEXT = 500;

    public const MAX_NOTE = 500;

    /**
     * Adds a catalogue product, or increases it if already present.
     *
     * Merging rather than appending: a buyer who adds the same chair twice
     * means eight chairs, not two lines of four, and two lines of the same SKU
     * is something a salesperson then has to reconcile by hand.
     */
    public function addProduct(QuoteBasket $basket, Product $product, int $qty, ?string $note = null): QuoteBasketItem
    {
        $this->assertHasRoom($basket);

        $rules = QuantityRules::forProduct($product);

        $existing = $basket->items()->where('product_id', $product->id)->first();

        if ($existing instanceof QuoteBasketItem) {
            $existing->qty = $rules->coerce($existing->qty + max(1, $qty));

            if ($note !== null) {
                $existing->customer_note = $this->clean($note, self::MAX_NOTE);
            }

            $existing->save();
            $basket->touchExpiry();

            return $existing;
        }

        $item = $basket->items()->create([
            'product_id' => $product->id,
            'qty' => $rules->coerce(max(1, $qty)),
            'customer_note' => $note === null ? null : $this->clean($note, self::MAX_NOTE),
        ]);

        $basket->touchExpiry();

        return $item;
    }

    /**
     * Adds a line for something the catalogue does not list.
     *
     * OfficeLand supplies far more than it lists, so a buyer working from a
     * requisition should not have to drop the items without a page. These
     * lines are stripped of markup and capped: they are copied verbatim into
     * an email, and the email is HTML.
     */
    public function addFreeText(QuoteBasket $basket, string $description, int $qty, ?string $note = null): QuoteBasketItem
    {
        $this->assertHasRoom($basket);

        $clean = $this->clean($description, self::MAX_FREE_TEXT);

        if ($clean === null) {
            throw new RuntimeException('A free-text line needs a description.');
        }

        $item = $basket->items()->create([
            'free_text_description' => $clean,
            'qty' => (new QuantityRules)->coerce(max(1, $qty)),
            'customer_note' => $note === null ? null : $this->clean($note, self::MAX_NOTE),
        ]);

        $basket->touchExpiry();

        return $item;
    }

    /**
     * Sets a line's quantity, coerced to what the product allows.
     *
     * The rules are re-read from the product rather than trusted from the
     * request: a product's minimum can change after a line was added, and the
     * quote must reflect what is orderable now.
     */
    public function setQuantity(QuoteBasketItem $item, int $qty): QuoteBasketItem
    {
        // loadMissing, not a bare access: lazy loading is disabled, and this
        // is reached from a controller that may not have loaded the product.
        $item->loadMissing(['product', 'basket']);

        $rules = QuantityRules::forProduct($item->product);

        $item->qty = $rules->coerce(max(1, $qty));
        $item->save();

        $this->touchParent($item);

        return $item;
    }

    public function setNote(QuoteBasketItem $item, ?string $note): QuoteBasketItem
    {
        $item->customer_note = $note === null ? null : $this->clean($note, self::MAX_NOTE);
        $item->save();

        $this->touchParent($item);

        return $item;
    }

    public function remove(QuoteBasketItem $item): void
    {
        $item->loadMissing('basket');

        $basket = $item->basket;

        $item->delete();

        if ($basket instanceof QuoteBasket) {
            $basket->touchExpiry();
        }
    }

    public function clear(QuoteBasket $basket): void
    {
        $basket->items()->delete();

        $basket->touchExpiry();
    }

    /**
     * Refreshes the parent basket's expiry after a line changed.
     *
     * Guarded because the relation is typed nullable even though the foreign
     * key is NOT NULL. A line whose basket has genuinely vanished is a data
     * integrity problem, and crashing on it here would obscure that rather
     * than surface it.
     */
    private function touchParent(QuoteBasketItem $item): void
    {
        $item->loadMissing('basket');

        $basket = $item->basket;

        if ($basket instanceof QuoteBasket) {
            $basket->touchExpiry();
        }
    }

    private function assertHasRoom(QuoteBasket $basket): void
    {
        if ($basket->items()->count() >= self::MAX_LINES) {
            throw new RuntimeException(
                'A quote request is limited to '.self::MAX_LINES.' lines. Send this one and start another, or call us.'
            );
        }
    }

    /**
     * Strips markup and control characters, collapses whitespace, truncates.
     *
     * `strip_tags` rather than escaping, because this text is displayed in
     * several places - the basket, the confirmation email, the admin panel -
     * and storing it already-escaped means one of them will double-escape it.
     * Storing it clean and escaping at each output point is the rule that
     * survives contact with a new template.
     */
    private function clean(string $value, int $limit): ?string
    {
        $clean = strip_tags($value);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean) ?? '';
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
        $clean = trim($clean);

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, $limit);
    }
}
