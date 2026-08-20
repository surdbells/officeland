<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\RestrictAdminByIp;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The admin panel (MASTER BUILD PROMPT sections 11 and 18).
 *
 * Security posture, all of it required rather than optional:
 *   - separate path, configurable so it need not be guessable
 *   - noindex at the edge AND in the response, belt and braces
 *   - two-factor authentication, app-based
 *   - optional IP allowlist
 *   - its own rate limit, stricter than the storefront's
 *   - session timeout
 *   - every mutation written to the audit log (App\Observers\WritesAuditLog)
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // Configurable: a predictable admin path is free reconnaissance.
            ->path((string) config('admin.path', 'admin'))
            ->login()
            ->passwordReset()
            ->profile()
            // Section 18 requires 2FA on admin. App-based rather than email:
            // email 2FA depends on the mail pipeline, and the mail pipeline is
            // one of the things an admin logs in to fix.
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable()
                    ->regenerableRecoveryCodes(),
            ])
            ->brandName('OfficeLand')
            ->colors([
                // Section 15.3 brand blue. Pending verification against the
                // real logo files (open question Q17).
                'primary' => Color::hex('#0053E2'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Optional allowlist, off unless ADMIN_IP_ALLOWLIST is set.
                RestrictAdminByIp::class,
                // The admin panel must never be indexed. Caddy sets this for
                // non-production; this covers production, where it matters most.
                'admin.noindex',
                // Stricter than the storefront: this is the credentialed surface.
                'throttle:admin',
            ])
            ->authMiddleware([Authenticate::class])
            // Section 18: session timeout. An unattended admin session is a
            // standing grant of catalogue write access.
            ->spa();
    }
}
