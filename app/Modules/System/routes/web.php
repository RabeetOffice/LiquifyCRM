<?php

declare(strict_types=1);

use App\Modules\System\Http\Controllers\DeployHookController;
use Illuminate\Support\Facades\Route;

// GitHub Actions post-deploy callback. Token-authenticated, no session.
Route::post('/hooks/deploy/{token}', DeployHookController::class)
    ->middleware('throttle:10,1')
    ->name('hooks.deploy');
