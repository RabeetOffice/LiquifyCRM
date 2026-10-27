<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * An Admin or IT sets somebody else's password from People (user request,
 * 27 Oct 2026). Who may is UserPolicy::changePassword. The person is signed
 * out everywhere (their sessions and "remember me"), told on the bell, and
 * the log says who did it, never the password.
 */
final class SetPersonPassword
{
    /** The rule a new password must meet: 12 characters for Admins and IT, 10 for everybody else. */
    public static function rule(User $person): Password
    {
        return Password::min($person->isGlobalAdmin() || $person->isIt() ? 12 : 10)->letters()->numbers();
    }

    public function handle(User $person, string $password, User $actor): void
    {
        $person->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $person->getKey())->delete();
        }

        Audit::log('members.password_set', $person, ['email' => $person->email], 'Password set by '.$actor->name, causer: $actor, logName: 'members');

        $person->notify(new AppNotification(
            title: 'Your password was changed',
            body: $actor->name.' set a new password for your account. If you did not ask for this, tell your Admin.',
            status: 'warning',
            icon: 'heroicon-o-key',
        ));
    }
}
