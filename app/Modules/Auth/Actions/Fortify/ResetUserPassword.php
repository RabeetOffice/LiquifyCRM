<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions\Fortify;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    /** @param  array<string, mixed>  $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'],
        ])->validate();

        $user->forceFill(['password' => $input['password']])->save();
    }
}
