<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Writes the authentication trail to the structured log (Phase 13a, §19).
 *
 * ── What was missing ─────────────────────────────────────────────────────
 * Nothing recorded a failed login. Filament caps attempts at five per minute
 * per key, so a brute-force attempt cannot succeed quickly — but "cannot
 * succeed quickly" is not the same as "somebody will notice". Without this,
 * a fortnight of patient credential stuffing against the admin panel leaves no
 * trace anywhere, and the first evidence of a compromise is its consequences.
 *
 * OWASP A09 is precisely this: the controls existed, the record did not.
 *
 * ── What is deliberately NOT logged ──────────────────────────────────────
 * Never the password, and never the whole request. The email is recorded on a
 * FAILED attempt because that is the fact that makes the log useful — one
 * address tried against many passwords looks completely different from many
 * addresses tried once — and because a failed attempt's email is, by
 * definition, either a real user being attacked or an attacker's guess.
 *
 * ── Why `warning` for a failure ──────────────────────────────────────────
 * So it clears a level filter. A failed login at `info` is a line nobody has
 * ever alerted on; at `warning` it reaches whatever is watching (Phase 13e).
 */
final class LogAuthenticationEvents
{
    public function __construct(private readonly Request $request) {}

    public function handleFailed(Failed $event): void
    {
        Log::warning('auth.failed', [
            // Read defensively: `credentials` is whatever the guard was handed,
            // and a custom guard could put something other than a string here.
            'email' => is_string($event->credentials['email'] ?? null)
                ? $event->credentials['email']
                : null,
            'guard' => $event->guard,
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            // Whether the account exists at all. A run of failures against a
            // real account is an attack; against addresses that do not exist
            // it is usually a scanner working through a leaked list.
            'user_exists' => $event->user !== null,
        ]);
    }

    /**
     * The rate limiter tripping is the strongest single signal available here:
     * it means somebody made five attempts in a minute, which no person
     * mistyping their own password does.
     */
    public function handleLockout(Lockout $event): void
    {
        Log::warning('auth.lockout', [
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    public function handleLogin(Login $event): void
    {
        Log::info('auth.login', [
            'user_id' => $event->user->getAuthIdentifier(),
            'guard' => $event->guard,
            'ip' => $this->request->ip(),
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        Log::info('auth.logout', [
            'user_id' => $event->user->getAuthIdentifier(),
            'guard' => $event->guard,
            'ip' => $this->request->ip(),
        ]);
    }

    /**
     * A password reset is an account takeover when it is not the account
     * owner doing it, and it is the step that makes a compromised mailbox
     * into a compromised admin panel.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        Log::warning('auth.password_reset', [
            'user_id' => $event->user->getAuthIdentifier(),
            'ip' => $this->request->ip(),
        ]);
    }
}
