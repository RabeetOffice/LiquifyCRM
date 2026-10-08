<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions\Fortify;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    /** @param  array<string, mixed>  $input */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => $input['password']])->save();

        Audit::log('auth.password_changed', $user, [], 'Password changed', causer: $user, logName: 'auth');
    }
}
