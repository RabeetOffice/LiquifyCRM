<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * A forgotten password, replaced with the 6-digit code of the person's
 * authenticator app instead of an emailed link. The code is the only proof
 * here, so wrong tries are counted per email and per address, every try is
 * logged, the person is told, and nobody is signed in: the sign-in screen
 * still asks for the new password and a fresh code.
 */
final class ResetPasswordWithAuthenticator
{
    /** Wrong tries in one hour before this way of resetting closes. */
    public const MAX_PER_EMAIL = 5;

    public const MAX_PER_ADDRESS = 20;

    private const DECAY_SECONDS = 3600;

    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    public function handle(string $email, string $code, string $password, string $ip): User
    {
        $email = mb_strtolower(trim($email));
        $limits = [
            self::emailKey($email) => self::MAX_PER_EMAIL,
            'authenticator-reset:ip:'.$ip => self::MAX_PER_ADDRESS,
        ];

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);

                throw ValidationException::withMessages([
                    'code' => 'Too many wrong tries. Try again in '.$minutes.' '.str('minute')->plural($minutes).', or use the email link instead.',
                ]);
            }
        }

        $user = User::query()->where('email', $email)->first();

        // One answer for every reason, so this screen never says who has an account or an authenticator app.
        if ($user === null || ! $user->isActive() || ! $user->hasTwoFactorEnabled() || ! $this->codeMatches($user, $code)) {
            foreach (array_keys($limits) as $key) {
                RateLimiter::hit($key, self::DECAY_SECONDS);
            }

            Audit::log('auth.password_reset_failed', $user, ['email' => $email, 'via' => 'authenticator'], 'Failed password reset with the authenticator app', causer: $user, logName: 'auth');

            throw ValidationException::withMessages([
                'code' => 'That email and code do not match. Check the email, wait for a new code and try again.',
            ]);
        }

        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        RateLimiter::clear(self::emailKey($email));

        Audit::log('auth.password_reset', $user, ['via' => 'authenticator'], 'Password reset with the authenticator app', causer: $user, logName: 'auth');

        $user->notify(new AppNotification(
            'Your password was changed',
            'It was reset with the code from your authenticator app. If this was not you, tell an Admin straight away.',
            'warning',
            route('profile.security'),
            mail: true,
            icon: 'heroicon-o-key',
            actionLabel: 'Open Password and 2FA',
        ));

        return $user;
    }

    public static function emailKey(string $email): string
    {
        return 'authenticator-reset:email:'.sha1(mb_strtolower(trim($email)));
    }

    private function codeMatches(User $user, string $code): bool
    {
        return $this->provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code);
    }
}
