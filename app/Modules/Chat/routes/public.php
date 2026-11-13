<?php

declare(strict_types=1);

use App\Modules\Chat\Http\Controllers\ChatFileController;
use App\Modules\Chat\Http\Controllers\WidgetAssetController;
use App\Modules\Chat\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

// The launcher script, the chat box and what it talks to. Loaded with no middleware on purpose: visitors of other
// websites get no session and no cookie from the CRM, and no framework CORS header (`/api/*` would answer every
// website): the launcher's config call names its own allowed origin. A request carries the widget key and the
// visitor's signed token (see WidgetController); each kind of call has its own rate limit.
Route::get('/chat/widget.js', [WidgetAssetController::class, 'script'])->name('chat.widget.script');
Route::get('/chat/frame/{key}', [WidgetAssetController::class, 'frame'])->name('chat.widget.frame');
// A file of a chat, for the visitor's browser: a link signed for six hours, good for that one file.
Route::get('/chat/files/{attachment}', [ChatFileController::class, 'visitor'])->middleware('signed')->whereNumber('attachment')->name('chat.widget.file');

Route::prefix('chat/api')->group(function (): void {
    Route::get('config', [WidgetController::class, 'config'])->middleware('throttle:chat-config')->name('chat.widget.config');
    Route::post('init', [WidgetController::class, 'init'])->middleware('throttle:chat-init')->name('chat.widget.init');
    Route::get('messages', [WidgetController::class, 'messages'])->middleware('throttle:chat-poll')->name('chat.widget.messages');
    Route::post('messages', [WidgetController::class, 'send'])->middleware('throttle:chat-send')->name('chat.widget.send');
    Route::post('realtime', [WidgetController::class, 'realtime'])->middleware('throttle:chat-poll')->name('chat.widget.realtime');
    Route::post('typing', [WidgetController::class, 'typing'])->middleware('throttle:chat-typing')->name('chat.widget.typing');
    // A simple request (plain text body): the browser sends it without asking first; the answer names its own allowed origin.
    Route::post('ping', [WidgetController::class, 'ping'])->middleware('throttle:chat-ping')->name('chat.widget.ping');
    Route::post('upload', [WidgetController::class, 'upload'])->middleware('throttle:chat-upload')->name('chat.widget.upload');
    Route::post('details', [WidgetController::class, 'details'])->middleware('throttle:chat-details')->name('chat.widget.details');
    Route::post('rate', [WidgetController::class, 'rate'])->middleware('throttle:chat-details')->name('chat.widget.rate');
    // A voice call from the chat box (docs/CALLS_PLAN.md): the servers to set it up with, the call itself, how it stands, hang up.
    Route::post('call/ice', [WidgetController::class, 'callIce'])->middleware('throttle:chat-call')->name('chat.widget.call.ice');
    Route::post('call', [WidgetController::class, 'callStart'])->middleware('throttle:chat-call')->name('chat.widget.call.start');
    Route::get('call/{call}', [WidgetController::class, 'callShow'])->middleware('throttle:chat-call-poll')->whereNumber('call')->name('chat.widget.call.show');
    Route::post('call/{call}/end', [WidgetController::class, 'callEnd'])->middleware('throttle:chat-call-poll')->whereNumber('call')->name('chat.widget.call.end');
});
