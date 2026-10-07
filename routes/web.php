<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// The brand workspace is the default panel; its own routes live under /b.
Route::redirect('/', '/b')->name('home');
