<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Optional IP allowlist for the admin panel (MASTER BUILD PROMPT section 18).
 *
 * Disabled when ADMIN_IP_ALLOWLIST is empty, which is the sensible default
 * while a team works from changing addresses.
 *
 * A blocked request gets 404, not 403. A 403 confirms that an admin panel
 * exists at this path and that the allowlist is the only thing in the way;
 * 404 says nothing.
 */
final class RestrictAdminByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowlist */
        $allowlist = (array) config('admin.ip_allowlist', []);

        if ($allowlist === []) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($ip !== null && IpUtils::checkIp($ip, $allowlist)) {
            return $next($request);
        }

        Log::warning('admin access blocked by ip allowlist', [
            'ip' => $ip,
            'path' => $request->path(),
        ]);

        throw new NotFoundHttpException;
    }
}
