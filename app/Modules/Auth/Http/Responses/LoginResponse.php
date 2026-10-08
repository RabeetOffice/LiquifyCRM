<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Responses;

use App\Modules\Auth\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/**
 * After sign-in: land in the default brand. A global admin with no brands
 * yet goes to the admin area to create the first one; a member with no
 * active brand is signed out again with a clear message.
 */
final class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->intended('/');
        }

        $brand = $user->defaultBrand();

        if ($brand === null && $user->isGlobalAdmin()) {
            return redirect()->intended(route('admin.brands.index'));
        }

        if ($brand === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Your account is not a member of any brand yet. Ask your Admin or Manager to invite you.');
        }

        return redirect()->intended($brand->url());
    }
}
