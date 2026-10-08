<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions\Fortify;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /** @param  array<string, mixed>  $input */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->getKey())],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ])->validateWithBag('updateProfileInformation');

        $user->forceFill([
            'name' => $input['name'],
            'email' => mb_strtolower(trim((string) $input['email'])),
            'phone' => $input['phone'] ?? null,
            'timezone' => $input['timezone'] ?? null,
        ])->save();

        Audit::log('members.profile_updated', $user, [], 'Profile updated', causer: $user, logName: 'members');
    }
}
