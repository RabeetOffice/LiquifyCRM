<?php

declare(strict_types=1);

use App\Modules\Brands\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invite/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});
