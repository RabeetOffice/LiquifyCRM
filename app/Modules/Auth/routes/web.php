<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\AuthenticatorResetController;
use Illuminate\Support\Facades\Route;

// Forgot password, answered with the authenticator app instead of an email.
Route::middleware(['guest', 'throttle:30,1'])->group(function (): void {
    Route::get('/forgot-password/authenticator', [AuthenticatorResetController::class, 'show'])->name('password.authenticator');
    Route::post('/forgot-password/authenticator', [AuthenticatorResetController::class, 'store'])->name('password.authenticator.update');
});
