<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * A local initials avatar, rendered inline as a data:-URI SVG.
 *
 * Filament's default provider points the user avatar at ui-avatars.com - an
 * external request made on every admin page. The admin CSP only allows images
 * from 'self', data: and blob:, so that request is blocked and the avatar renders
 * as a broken image; it also leaks the user's name to a third party. This draws
 * the same initials avatar inline, so there is no network request and nothing for
 * the CSP to block.
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));

        $initials = collect(preg_split('/\s+/', $name) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $segment): string => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->implode('');

        if ($initials === '') {
            $initials = '?';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
            .'<rect width="96" height="96" rx="48" fill="#09090b"/>'
            .'<text x="48" y="48" dy=".35em" text-anchor="middle" '
            .'font-family="system-ui, -apple-system, sans-serif" font-size="38" font-weight="600" fill="#ffffff">'
            .e($initials)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
