<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Password change and the authenticator app (two-factor authentication). */
#[Layout('components.layouts.app')]
#[Title('Password and 2FA')]
final class ProfileSecurity extends Component
{
    use WithConfirmation;
    use WithToasts;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $code = '';

    public bool $showRecoveryCodes = false;

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        $user = $this->user();
        $user->forceFill(['password' => $this->password])->save();

        Audit::log('auth.password_changed', $user, [], 'Password changed', causer: $user, logName: 'auth');

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toastSuccess('Password changed');
    }

    public function enableTwoFactor(): void
    {
        $this->askConfirmation('doEnableTwoFactor', [], 'Set up an authenticator app', 'Confirm your password to generate the QR code.', 'Continue', 'primary', password: true);
    }

    public function doEnableTwoFactor(): void
    {
        app(EnableTwoFactorAuthentication::class)($this->user());
        $this->code = '';
    }

    public function confirmTwoFactor(): void
    {
        $user = $this->user();

        app(ConfirmTwoFactorAuthentication::class)($user, trim($this->code));

        Audit::log('auth.two_factor_enabled', $user, [], 'Two-factor authentication turned on', causer: $user, logName: 'auth');

        $this->code = '';
        $this->showRecoveryCodes = true;
        $this->toastSuccess('Two-factor authentication is on', 'Save your recovery codes somewhere safe.');
    }

    public function cancelTwoFactorSetup(): void
    {
        $this->doDisableTwoFactor(silent: true);
    }

    public function disableTwoFactor(): void
    {
        $this->askConfirmation('doDisableTwoFactor', [], 'Turn off two-factor authentication?', 'Your account will be protected by your password only.', 'Turn off', 'danger', password: true);
    }

    public function doDisableTwoFactor(bool $silent = false): void
    {
        $user = $this->user();

        app(DisableTwoFactorAuthentication::class)($user);

        if (! $silent) {
            Audit::log('auth.two_factor_disabled', $user, [], 'Two-factor authentication turned off', causer: $user, logName: 'auth');
            $this->toastWarning('Two-factor authentication is off');
        }

        $this->showRecoveryCodes = false;
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->askConfirmation('doRegenerateRecoveryCodes', [], 'Generate new recovery codes?', 'The old codes stop working.', 'Generate', 'primary', password: true);
    }

    public function doRegenerateRecoveryCodes(): void
    {
        app(GenerateNewRecoveryCodes::class)($this->user());
        $this->showRecoveryCodes = true;
        $this->toastSuccess('New recovery codes generated');
    }

    public function render(): View
    {
        $user = $this->user()->fresh() ?? $this->user();
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $enabled = $user->hasTwoFactorEnabled();

        return view('auth::livewire.profile-security', [
            'user' => $user,
            'pending' => $pending,
            'enabled' => $enabled,
            'required' => $user->requiresTwoFactor(),
            'qrCode' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'secret' => $pending ? decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => ($enabled && $this->showRecoveryCodes) ? $user->recoveryCodes() : [],
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
