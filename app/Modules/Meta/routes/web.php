<?php

declare(strict_types=1);

use App\Modules\Meta\Http\Controllers\MetaWebhookController;
use Illuminate\Support\Facades\Route;

// Meta webhooks: verify token on GET, X-Hub-Signature-256 on POST. No session.
Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'receive'])
    ->middleware('throttle:meta-webhook')
    ->name('webhooks.meta');
