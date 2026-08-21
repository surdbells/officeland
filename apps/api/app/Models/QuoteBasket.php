<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A quote basket, identified by a cookie and owned by the server.
 *
 * ── Why the token is stored as a hash ────────────────────────────────────
 * The cookie carries a random token; this table stores only its SHA-256. A
 * database leak therefore does not hand anyone else's basket to an attacker,
 * which matters because a basket is about to carry an email address and a
 * phone number. It is the same reasoning as never storing a session ID in
 * plaintext, and it costs one hash per request.
 *
 * ── Why the basket lives here rather than in the cookie ──────────────────
 * §9a requires the server to be authoritative for line-item identity. A
 * client-side basket would let anyone submit a quote for a product that does
 * not exist, at a quantity the product forbids, with a name and specification
 * of their choosing — and every one of those would be copied verbatim into the
 * email a salesperson acts on.
 *
 * @property int $id
 * @property string $uuid
 * @property string $session_token_hash
 * @property string $status
 * @property Carbon|null $expires_at
 */
final class QuoteBasket extends Model
{
    use HasPublicUuid;

    protected $table = 'quote_baskets';

    protected $fillable = [
        'uuid',
        'session_token_hash',
        'status',
        'expires_at',
    ];

    /** How long an untouched basket survives. Refreshed on every write. */
    public const LIFETIME_DAYS = 30;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return HasMany<QuoteBasketItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteBasketItem::class, 'basket_id')->orderBy('id');
    }

    /**
     * The cookie value is never stored, only compared.
     *
     * A plain SHA-256 rather than a password hash: the token is 32 random
     * bytes from the server, so there is nothing to brute-force and a slow
     * hash would only add latency to every request.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function touchExpiry(): void
    {
        $this->forceFill(['expires_at' => now()->addDays(self::LIFETIME_DAYS)])->save();
    }
}
