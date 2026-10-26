<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Auth\Actions\Fortify\ResetUserPassword;
use App\Modules\Auth\Actions\Fortify\UpdateUserPassword;
use App\Modules\Auth\Actions\Fortify\UpdateUserProfileInformation;
use App\Modules\Auth\Http\Responses\LoginResponse;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\Device;
use App\Modules\Auth\Support\SignInGate;
use App\Modules\Auth\Support\SignInRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Sign-in, password reset, two-factor and profile updates come from Laravel
 * Fortify (headless); the screens are LiquifyCRM's own Blade views.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, LoginResponse::class);
    }

    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Why somebody was signed out by sign-in security, until they sign in again.
        Fortify::loginView(fn (Request $request) => view('auth.login', ['refused' => $request->session()->get(SignInGate::SESSION_REFUSED)]));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        // Suspended people cannot sign in, whatever their password.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', mb_strtolower(trim((string) $request->input('email'))))->first();

            if ($user === null || ! $user->isActive() || ! password_verify((string) $request->input('password'), $user->password)) {
                return null;
            }

            // Sign-in security: the right password from a blocked address, outside the office or on a phone
            // that is not allowed is refused with the reason, so the person knows whom to ask.
            if (SignInRules::enforced()) {
                $device = Device::of($request, is_string($request->input('device')) ? $request->input('device') : null);
                $request->session()->put(SignInGate::SESSION_DEVICE, $device->kind->value);
                $refusal = app(SignInGate::class)->enforce($user, $request, $device, signingIn: true);

                if ($refusal !== null) {
                    throw ValidationException::withMessages(['email' => $refusal->message()]);
                }
            }

            return $user;
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));
    }
}
