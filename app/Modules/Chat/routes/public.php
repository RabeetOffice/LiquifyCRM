<?php

declare(strict_types=1);

use App\Modules\Chat\Http\Controllers\WidgetAssetController;
use App\Modules\Chat\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

// The launcher script, the chat box and what it talks to. Loaded with no middleware on purpose: visitors of other
// websites get no session and no cookie from the CRM, and no framework CORS header (`/api/*` would answer every
// website): the launcher's config call names its own allowed origin. A request carries the widget key and the
// visitor's signed token (see WidgetController); each kind of call has its own rate limit.
Route::get('/chat/widget.js', [WidgetAssetController::class, 'script'])->name('chat.widget.script');
Route::get('/chat/frame/{key}', [WidgetAssetController::class, 'frame'])->name('chat.widget.frame');

Route::prefix('chat/api')->group(function (): void {
    Route::get('config', [WidgetController::class, 'config'])->middleware('throttle:chat-config')->name('chat.widget.config');
    Route::post('init', [WidgetController::class, 'init'])->middleware('throttle:chat-init')->name('chat.widget.init');
    Route::get('messages', [WidgetController::class, 'messages'])->middleware('throttle:chat-poll')->name('chat.widget.messages');
    Route::post('messages', [WidgetController::class, 'send'])->middleware('throttle:chat-send')->name('chat.widget.send');
    Route::post('typing', [WidgetController::class, 'typing'])->middleware('throttle:chat-typing')->name('chat.widget.typing');
    Route::post('details', [WidgetController::class, 'details'])->middleware('throttle:chat-details')->name('chat.widget.details');
});
