<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Requests;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Invitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $invitation = Invitation::query()->acrossBrands()->where('token', $this->route('token'))->first();
        $hasAccount = $invitation !== null && User::query()->where('email', $invitation->email)->exists();

        if ($hasAccount) {
            return [
                'accept' => ['accepted'],
            ];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'accept.accepted' => 'Tick the box to join this brand with your existing account.',
        ];
    }
}
