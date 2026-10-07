<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Responses;

use App\Modules\Auth\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;

/**
 * After sign-in: land in the default brand. A global admin with no brands
 * yet goes to the admin area to create the first one; a member with no
 * active brand is signed out again with a clear message.
 */
final class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $user = $request->user();

        if ($panel !== null && $panel->hasTenancy() && $user instanceof User) {
            $hasTenant = $user->getTenants($panel)->isNotEmpty();

            if (! $hasTenant && $user->isGlobalAdmin()) {
                return redirect()->to('/admin/brands');
            }

            if (! $hasTenant) {
                Filament::auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                Notification::make()
                    ->title('No brand access yet')
                    ->body('Your account is not a member of any brand. Ask your Admin or Manager to invite you.')
                    ->warning()
                    ->send();

                return redirect()->to($panel->getLoginUrl() ?? '/');
            }
        }

        return redirect()->intended(Filament::getUrl());
    }
}
