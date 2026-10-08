<?php

declare(strict_types=1);

use App\Modules\Sites\Http\Controllers\IngestSiteController;
use Illuminate\Support\Facades\Route;

// Website leads (liquify.php, Elementor webhook). Signature authenticated, no session.
Route::post('/v1/ingest/site/{site_key}', IngestSiteController::class)
    ->middleware('throttle:ingest')
    ->name('api.ingest.site');
