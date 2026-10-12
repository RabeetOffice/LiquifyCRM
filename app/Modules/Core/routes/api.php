<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\LiveController;
use Illuminate\Support\Facades\Route;

// Live updates: the stamps of the page's brand and person. Signed by the page itself, so no session is opened.
Route::get('/live', LiveController::class)->name('live');
