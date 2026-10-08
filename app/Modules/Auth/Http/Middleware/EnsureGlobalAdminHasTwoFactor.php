<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is optional for Managers and Agents but forced
 * for Super Admins and Admins: a global admin without an authenticator app
 * is sent to the security page until it is set up.
 */
final class EnsureGlobalAdminHasTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user instanceof User
            && $user->requiresTwoFactor()
            && ! $user->hasTwoFactorEnabled()
            && ! $request->routeIs('profile.security', 'logout')
        ) {
            return redirect()
                ->route('profile.security')
                ->with('status', 'Set up your authenticator app to continue. Two-factor authentication is required for Admins.');
        }

        return $next($request);
    }
}
