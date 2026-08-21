<?php

declare(strict_types=1);

namespace App\Quotes;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteRoutingRule;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides which inbox a quote notification reaches (§11d).
 *
 * ── Why route at all ─────────────────────────────────────────────────────
 * One shared inbox works until it doesn't. When every salesperson receives
 * every lead, the ones nobody owns are the ones nobody answers — the diffusion
 * of responsibility is the failure mode, not the volume. Routing furniture
 * enquiries to the furniture team makes ownership the default.
 *
 * ── Longest-prefix match on the primary category ─────────────────────────
 * A quote spanning departments still has to land somewhere. The first line's
 * primary category decides, because that is what the buyer reached for first
 * and is the best available guess at what the enquiry is really about.
 *
 * Longest prefix rather than first match, so `/it-products/printers/` can be
 * routed separately from `/it-products/` without depending on the order
 * somebody happened to write the config in.
 *
 * ── Always falls back ────────────────────────────────────────────────────
 * An unroutable quote goes to the default inbox. Silently dropping a lead
 * because its category has no rule would be the worst possible outcome of a
 * feature meant to make leads more likely to be answered.
 */
final class QuoteRouting
{
    /**
     * The address a quote's internal notification should go to.
     */
    public function recipientFor(Quote $quote): string
    {
        $path = $this->primaryCategoryPath($quote);

        if ($path !== null) {
            $match = $this->longestPrefixMatch($path);

            if ($match !== null) {
                return $match;
            }
        }

        return $this->default();
    }

    /**
     * A quote that does not exist, for the routing test-send (§11d).
     *
     * Built in memory and never saved: the test must not create a lead in the
     * queue that somebody then tries to answer, and it must not disclose a
     * real customer's details to an address typed into a form.
     *
     * The reference is deliberately not in the OL-Q-YYYY-NNNNNN format — a
     * test that looks exactly like the real thing is a test somebody chases.
     */
    public static function sampleQuote(): Quote
    {
        $quote = new Quote([
            'reference' => 'OL-Q-TEST',
            'email' => 'sample.customer@example.com',
            'phone' => '+234 800 000 0000',
            'message' => 'This is a test of quote notification routing. No customer sent it, and there is nothing to answer.',
            'status' => 'new',
            'source' => 'web',
            'submitted_at' => now(),
        ]);

        $item = new QuoteItem([
            'product_name_snapshot' => 'SAMPLE — Ergonomic Task Chair',
            'sku_snapshot' => 'SAMPLE-001',
            'brand_name_snapshot' => 'Sample',
            'qty' => 12,
            'customer_note' => 'Delivered to Victoria Island.',
            'sort_order' => 0,
        ]);

        // Attached rather than queried: an unsaved quote has no id, so
        // `items()` would fetch nothing at all.
        $quote->setRelation('items', new Collection([$item]));

        return $quote;
    }

    public function default(): string
    {
        $default = config('quotes.routing.default');

        return is_string($default) && $default !== ''
            ? $default
            : (string) config('mail.sales_address', 'sales@officelandng.com');
    }

    /**
     * Every active route, keyed by the category path it matches.
     *
     * Database rules win over config ones for the same path. Config routing
     * predates the admin screen and is kept as a seedable default, but once
     * somebody has set a rule in the UI, the UI is the truth — otherwise a
     * change made in the admin appears to do nothing, which is worse than
     * having no admin at all.
     *
     * @return array<string, string> category path => address
     */
    public function overrides(): array
    {
        return array_merge($this->configuredOverrides(), $this->storedOverrides());
    }

    /**
     * The rules as rows, for the admin screen and the test-send.
     *
     * @return Collection<int, QuoteRoutingRule>
     */
    public function rules(): Collection
    {
        return QuoteRoutingRule::query()
            ->with('category')
            ->orderBy('category_id')
            ->get();
    }

    /** @return array<string, string> */
    private function storedOverrides(): array
    {
        $routes = [];

        foreach ($this->rules() as $rule) {
            $path = $rule->category?->path;

            if (! $rule->is_active || ! is_string($path) || $path === '' || $rule->recipient === '') {
                continue;
            }

            $routes[$path] = $rule->recipient;
        }

        return $routes;
    }

    /** @return array<string, string> */
    private function configuredOverrides(): array
    {
        $configured = config('quotes.routing.categories');

        if (! is_array($configured)) {
            return [];
        }

        $routes = [];

        foreach ($configured as $path => $address) {
            if (is_string($path) && is_string($address) && $address !== '') {
                $routes[$path] = $address;
            }
        }

        return $routes;
    }

    private function longestPrefixMatch(string $path): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach ($this->overrides() as $prefix => $address) {
            if (str_starts_with($path, $prefix) && strlen($prefix) > $bestLength) {
                $best = $address;
                $bestLength = strlen($prefix);
            }
        }

        return $best;
    }

    /**
     * The primary category of the quote's first line.
     *
     * Read from the LIVE product rather than the snapshot: the snapshot
     * records what the customer asked for, which is the right thing to show
     * them, but routing is about who should handle it now.
     */
    private function primaryCategoryPath(Quote $quote): ?string
    {
        $item = $quote->items()->with('product.primaryCategory')->orderBy('sort_order')->first();

        $category = $item?->product?->primaryCategory;

        return $category === null ? null : (string) $category->path;
    }
}
