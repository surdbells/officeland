<?php

declare(strict_types=1);

namespace App\Quotes;

use App\Models\Product;

/**
 * Coerces a requested quantity to what the product actually allows (§9b).
 *
 * ── Coerce, do not reject ────────────────────────────────────────────────
 * A buyer who types 7 against a product sold in multiples of 5 has not made
 * an error worth an error message — they want about seven. Rounding UP to 10
 * and saying so is the behaviour that gets the quote sent; a validation error
 * is the behaviour that loses it.
 *
 * Rounding up rather than down matters for the same reason: rounding 7 down to
 * 5 silently gives the buyer less than they asked for, and they will not
 * notice until the goods arrive.
 *
 * ── Why this is server-side ──────────────────────────────────────────────
 * The storefront applies the same rules in its steppers, but that is a
 * convenience. §9a makes the server authoritative, and quantity is exactly the
 * field where a crafted request would otherwise put "1" against a product with
 * a minimum order of 50 — producing a quote a salesperson would have to catch
 * by eye.
 */
final readonly class QuantityRules
{
    /**
     * A hard ceiling on any single line.
     *
     * Not a business rule — a genuine order of 10,000 chairs is welcome — but
     * a bound on what one request can put into an email. Anything above it is
     * either a typo or an attempt to make the confirmation email enormous.
     */
    public const MAX_QTY = 100000;

    public function __construct(
        public int $minimum = 1,
        public int $step = 1,
    ) {}

    public static function forProduct(?Product $product): self
    {
        if ($product === null) {
            // A free-text line has no product to carry rules, so it gets the
            // permissive default.
            return new self;
        }

        return new self(
            minimum: max(1, (int) $product->min_order_qty),
            step: max(1, (int) $product->qty_step),
        );
    }

    /**
     * The nearest allowed quantity at or above what was asked for.
     */
    public function coerce(int $requested): int
    {
        $qty = max($requested, $this->minimum);

        if ($this->step > 1) {
            /*
             * Steps count FROM the minimum, not from zero. A product with a
             * minimum of 4 sold in multiples of 5 allows 4, 9, 14 — not 5, 10,
             * 15, which would exclude the minimum the product itself declares.
             */
            $above = $qty - $this->minimum;
            $qty = $this->minimum + (int) (ceil($above / $this->step) * $this->step);
        }

        return min($qty, self::MAX_QTY);
    }

    public function allows(int $qty): bool
    {
        return $qty === $this->coerce($qty);
    }

    /** Human-readable, and only when the rules actually constrain anything. */
    public function describe(): ?string
    {
        if ($this->minimum > 1 && $this->step > 1 && $this->step !== $this->minimum) {
            return "Minimum {$this->minimum}, in multiples of {$this->step}";
        }

        if ($this->minimum > 1) {
            return "Minimum order {$this->minimum}";
        }

        if ($this->step > 1) {
            return "Sold in multiples of {$this->step}";
        }

        return null;
    }
}
