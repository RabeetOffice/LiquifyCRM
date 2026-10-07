<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is optional for Managers and Agents but forced
 * for Super Admins and Admins. The admin panel requires it natively; this
 * middleware applies the same rule inside the brand panel by sending a
 * global admin without an authenticator app to the admin set-up page.
 */
final class EnsureGlobalAdminHasTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            $url = Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl();

            if ($url !== null && ! $request->is(ltrim((string) parse_url($url, PHP_URL_PATH), '/').'*')) {
                return redirect()->to($url);
            }
        }

        return $next($request);
    }
}
