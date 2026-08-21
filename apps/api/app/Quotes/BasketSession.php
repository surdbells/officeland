<?php

declare(strict_types=1);

namespace App\Quotes;

use App\Models\QuoteBasket;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Resolves the caller's basket from a cookie, creating one on demand (§9a).
 *
 * ── What is in the cookie ────────────────────────────────────────────────
 * A random 32-byte token. Not the basket UUID, not its ID, and nothing about
 * its contents. The token is the only secret; everything else lives in
 * Postgres, keyed by the token's SHA-256.
 *
 * The cookie is HttpOnly (script cannot read it), Secure outside local
 * development, and SameSite=Lax. Lax rather than Strict because a buyer
 * arriving from a Google result and adding an item in the same visit must keep
 * the same basket — Strict would drop the cookie on that first cross-site
 * navigation and silently start them a new one.
 *
 * ── Why not Laravel's session ────────────────────────────────────────────
 * There are no accounts (D-003), so a session exists only to carry this. A
 * dedicated cookie keeps the basket independent of session lifetime and CSRF
 * middleware, and makes the storage explicit: one row, one expiry, one reaper.
 *
 * ── The cookie is deferred, never set eagerly ────────────────────────────
 * Under D-009 the storefront is static and cacheable at the edge. Setting a
 * cookie on a page GET would make every page response uncacheable and hand a
 * tracking cookie to visitors who never touch the basket. So the cookie is
 * issued only when a basket is actually created — that is, on the first write.
 */
final class BasketSession
{
    public const COOKIE = 'ol_basket';

    /** Matches QuoteBasket::LIFETIME_DAYS; the cookie must not outlive the row. */
    private const COOKIE_MINUTES = QuoteBasket::LIFETIME_DAYS * 24 * 60;

    /** Set when this request created a basket, so the caller can queue the cookie. */
    private ?string $issuedToken = null;

    /**
     * The caller's active basket, or null if they have never had one.
     *
     * Read-only: a GET must not create a basket, or every crawler would leave
     * one behind. Use {@see resolveOrCreate()} on a write.
     */
    public function resolve(Request $request): ?QuoteBasket
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        $basket = QuoteBasket::query()
            ->active()
            ->where('session_token_hash', QuoteBasket::hashToken($token))
            ->first();

        if ($basket === null || $basket->isExpired()) {
            return null;
        }

        return $basket;
    }

    public function resolveOrCreate(Request $request): QuoteBasket
    {
        $existing = $this->resolve($request);

        if ($existing !== null) {
            return $existing;
        }

        $token = Str::random(64);
        $this->issuedToken = $token;

        return QuoteBasket::query()->create([
            'uuid' => (string) Str::uuid(),
            'session_token_hash' => QuoteBasket::hashToken($token),
            'status' => 'active',
            'expires_at' => now()->addDays(QuoteBasket::LIFETIME_DAYS),
        ]);
    }

    /**
     * The cookie to attach to the response, if this request created a basket.
     *
     * Returns null when the caller already had one — re-issuing an identical
     * cookie on every write is noise, and it would extend the cookie's life
     * past the row's without extending the row's.
     */
    public function pendingCookie(): ?Cookie
    {
        if ($this->issuedToken === null) {
            return null;
        }

        return cookie(
            name: self::COOKIE,
            value: $this->issuedToken,
            minutes: self::COOKIE_MINUTES,
            path: '/',
            domain: null,
            secure: ! app()->environment('local', 'testing'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /**
     * Ends the basket's life after submission.
     *
     * The row is marked submitted rather than deleted: the quote references
     * it, and a buyer who submits and then presses back should see an empty
     * basket rather than the one they just sent.
     */
    public function close(QuoteBasket $basket): void
    {
        $basket->forceFill(['status' => 'submitted'])->save();
    }

    /** Clears the cookie once its basket is gone. */
    public function forgetCookie(): Cookie
    {
        return cookie()->forget(self::COOKIE);
    }
}
