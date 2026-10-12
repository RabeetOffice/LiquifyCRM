<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Actions\ResetPasswordWithAuthenticator;
use App\Modules\Auth\Http\Requests\AuthenticatorResetRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * "Forgot password" without email: the code from the authenticator app plus
 * a new password. Linked from the forgot-password screen.
 */
final class AuthenticatorResetController
{
    public function show(): View
    {
        return view('auth.authenticator-reset');
    }

    public function store(AuthenticatorResetRequest $request, ResetPasswordWithAuthenticator $reset): RedirectResponse
    {
        $reset->handle(
            (string) $request->validated('email'),
            (string) $request->validated('code'),
            (string) $request->validated('password'),
            (string) $request->ip(),
        );

        return redirect()->route('login')->with([
            'status' => 'Your new password is saved. Sign in with it; the authenticator app will be asked for a new code.',
            'status_type' => 'success',
        ]);
    }
}
