<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Auth\Console\InstallCommand;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Policies\UserPolicy;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;

final class AuthServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Auth';

    protected array $policies = [
        User::class => UserPolicy::class,
    ];

    protected array $commands = [
        InstallCommand::class,
    ];

    protected function bootModule(): void
    {
        // Sign-in events from the "What is logged" table.
        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;

            if (! $user instanceof User) {
                return;
            }

            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => request()->ip(),
            ])->saveQuietly();

            Audit::log('auth.login', $user, [], 'Signed in', causer: $user, logName: 'auth');
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User) {
                Audit::log('auth.logout', $event->user, [], 'Signed out', causer: $event->user, logName: 'auth');
            }
        });

        Event::listen(Failed::class, function (Failed $event): void {
            Audit::log(
                'auth.failed',
                $event->user instanceof User ? $event->user : null,
                ['email' => $event->credentials['email'] ?? null],
                'Failed sign-in attempt',
                causer: $event->user instanceof User ? $event->user : null,
                logName: 'auth',
            );
        });

        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User) {
                Audit::log('auth.password_reset', $event->user, [], 'Password reset', causer: $event->user, logName: 'auth');
            }
        });
    }
}
