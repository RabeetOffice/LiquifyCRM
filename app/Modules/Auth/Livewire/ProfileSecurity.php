<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    /** The code from the new app while moving two-factor to another app. */
    public string $newCode = '';

    private const REPLACEMENT = 'two_factor.replacement';

    /** Set on the server only: the codes show right after a password-confirmed step, never on the browser's word. */
    #[Locked]
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
        $this->ensureConfirmed();

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

    /** Leaves a setup that was never confirmed. Two-factor that is on is only turned off with the password. */
    public function cancelTwoFactorSetup(): void
    {
        if (! $this->user()->hasTwoFactorEnabled()) {
            $this->turnOffTwoFactor(silent: true);
        }
    }

    /** Moves two-factor to another app or phone: the current app keeps working until the new one is confirmed. */
    public function changeAuthenticator(): void
    {
        $this->askConfirmation('doChangeAuthenticator', [], 'Move to a new authenticator app', 'Confirm your password to get a new QR code. Your current app keeps working until you confirm the new one.', 'Continue', 'primary', password: true);
    }

    public function doChangeAuthenticator(): void
    {
        $this->ensureConfirmed();
        abort_unless($this->user()->hasTwoFactorEnabled(), 403);

        session()->put(self::REPLACEMENT, encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()));
        $this->newCode = '';
    }

    public function confirmNewAuthenticator(): void
    {
        $secret = $this->replacementSecret();

        if ($secret === null) {
            return;
        }

        $this->validate(['newCode' => ['required', 'string']], ['newCode.required' => 'Type the 6-digit code from the new app.']);

        if (! app(TwoFactorAuthenticationProvider::class)->verify($secret, trim($this->newCode))) {
            $this->addError('newCode', 'That code is not right. Check the new app and try again.');

            return;
        }

        $user = $this->user();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ])->save();

        session()->forget(self::REPLACEMENT);
        Audit::log('auth.two_factor_changed', $user, [], 'Authenticator app changed', causer: $user, logName: 'auth');

        $this->newCode = '';
        $this->toastSuccess('Authenticator app changed', 'Your old app no longer works for this account.');
    }

    public function cancelNewAuthenticator(): void
    {
        session()->forget(self::REPLACEMENT);
        $this->newCode = '';
        $this->resetErrorBag('newCode');
    }

    public function disableTwoFactor(): void
    {
        if ($this->user()->requiresTwoFactor()) {
            $this->askConfirmation('doDisableTwoFactor', [], 'Remove your authenticator app?', 'Admins must use an authenticator app, so you will set up a new one straight away. Your current app stops working now.', 'Remove', 'danger', password: true);

            return;
        }

        $this->askConfirmation('doDisableTwoFactor', [], 'Turn off two-factor authentication?', 'Your account will be protected by your password only.', 'Turn off', 'danger', password: true);
    }

    public function doDisableTwoFactor(): void
    {
        $this->ensureConfirmed();
        $this->turnOffTwoFactor(silent: false);
    }

    private function turnOffTwoFactor(bool $silent): void
    {
        $user = $this->user();

        app(DisableTwoFactorAuthentication::class)($user);
        session()->forget(self::REPLACEMENT);

        if (! $silent) {
            Audit::log('auth.two_factor_disabled', $user, [], 'Two-factor authentication turned off', causer: $user, logName: 'auth');
            $this->toastWarning('Two-factor authentication is off');
        }

        $this->showRecoveryCodes = false;
    }

    public function showCodes(): void
    {
        $this->askConfirmation('doShowRecoveryCodes', [], 'Show your recovery codes?', 'Confirm your password to see them.', 'Show', 'primary', password: true);
    }

    public function doShowRecoveryCodes(): void
    {
        $this->ensureConfirmed();
        $this->showRecoveryCodes = true;
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->askConfirmation('doRegenerateRecoveryCodes', [], 'Generate new recovery codes?', 'The old codes stop working.', 'Generate', 'primary', password: true);
    }

    public function doRegenerateRecoveryCodes(): void
    {
        $this->ensureConfirmed();

        app(GenerateNewRecoveryCodes::class)($this->user());
        $this->showRecoveryCodes = true;
        $this->toastSuccess('New recovery codes generated');
    }

    public function render(): View
    {
        $user = $this->user()->fresh() ?? $this->user();
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $enabled = $user->hasTwoFactorEnabled();
        $replacement = $enabled ? $this->replacementSecret() : null;

        return view('auth::livewire.profile-security', [
            'user' => $user,
            'pending' => $pending,
            'enabled' => $enabled,
            'required' => $user->requiresTwoFactor(),
            'qrCode' => $pending ? $user->twoFactorQrCodeSvg() : null,
            'secret' => $pending ? decrypt($user->two_factor_secret) : null,
            'replacement' => $replacement,
            'replacementQr' => $replacement !== null ? $this->qrCodeSvg($user, $replacement) : null,
            'recoveryCodes' => ($enabled && $this->showRecoveryCodes) ? $user->recoveryCodes() : [],
        ]);
    }

    /** The new app's key while a move to another app is open; kept in the session, never in the page's state. */
    private function replacementSecret(): ?string
    {
        $stored = session(self::REPLACEMENT);

        return is_string($stored) ? decrypt($stored) : null;
    }

    /** The same QR code Fortify draws, for a key that is not saved on the account yet. */
    private function qrCodeSvg(User $user, string $secret): string
    {
        $url = app(TwoFactorAuthenticationProvider::class)->qrCodeUrl(config('app.name'), $user->email, $secret);

        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
            new SvgImageBackEnd,
        )))->writeString($url);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
