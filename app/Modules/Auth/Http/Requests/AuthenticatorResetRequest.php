<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class AuthenticatorResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Authenticator apps show the code as "123 456". */
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => str_replace(' ', '', (string) $this->input('code'))]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.digits' => 'The code from your authenticator app has 6 digits.',
        ];
    }
}
