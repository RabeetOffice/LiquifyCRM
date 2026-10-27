<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Auth\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** /b: open the person's default brand, or the admin area when they have none. */
final class BrandRedirectController
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->homeUrl() !== null) {
            return redirect()->to($user->homeUrl());
        }

        $brand = $user->defaultBrand();

        if ($brand !== null) {
            return redirect()->to($brand->url());
        }

        if ($user->isGlobalAdmin()) {
            return redirect()->route('admin.brands.index');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account is not a member of any brand yet. Ask your Admin or Manager to invite you.');
    }
}
