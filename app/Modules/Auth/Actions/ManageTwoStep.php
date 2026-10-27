<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;

/**
 * Somebody else's two-step (authenticator app), looked after from one panel
 * by an Admin or IT (user request, 27 Oct 2026): turned on with a key of its
 * own for each person, so nobody has to set it up on every computer, and a
 * leaked key opens one account, not all of them. Who may is
 * UserPolicy::manageTwoStep. Every change is logged and told to the person.
 */
final class ManageTwoStep
{
    /** Turn two-step on (or give a new key): the person's old app, if any, stops working. */
    public function turnOn(User $person, User $actor): void
    {
        $renewed = $person->hasTwoFactorEnabled();

        app(EnableTwoFactorAuthentication::class)($person, force: true);
        $person->forceFill(['two_factor_confirmed_at' => now()])->save();

        Audit::log($renewed ? 'auth.two_step_renewed' : 'auth.two_step_set', $person, ['email' => $person->email], ($renewed ? 'New two-step key given by ' : 'Two-step turned on by ').$actor->name, causer: $actor, logName: 'auth');

        $person->notify(new AppNotification(
            title: $renewed ? 'Your two-step code changed' : 'Two-step is on for your account',
            body: $actor->name.' set it up. From now on you sign in with your password and a 6-digit code: ask '.$actor->name.' for the QR code to add to your authenticator app.',
            status: 'info',
            icon: 'heroicon-o-key',
        ));
    }

    public function turnOff(User $person, User $actor): void
    {
        app(DisableTwoFactorAuthentication::class)($person);

        Audit::log('auth.two_step_removed', $person, ['email' => $person->email], 'Two-step turned off by '.$actor->name, causer: $actor, logName: 'auth');

        $person->notify(new AppNotification(
            title: 'Two-step is off for your account',
            body: $actor->name.' turned it off. You sign in with your password only.',
            status: 'warning',
            icon: 'heroicon-o-key',
        ));
    }

    /** The 6-digit code the person's authenticator shows right now, or null without two-step. */
    public static function currentCode(User $person): ?string
    {
        $secret = self::secret($person);

        return $secret !== null ? rescue(fn (): string => (new Google2FA)->getCurrentOtp($secret), null, false) : null;
    }

    /** Seconds until every code changes (they all change together, every 30 seconds). */
    public static function secondsLeft(): int
    {
        return 30 - (time() % 30);
    }

    /** The key behind the QR code, for typing into an app by hand. */
    public static function secret(User $person): ?string
    {
        if (! $person->hasTwoFactorEnabled() || $person->two_factor_secret === null) {
            return null;
        }

        return rescue(fn (): string => (string) decrypt($person->two_factor_secret), null, false);
    }
}
