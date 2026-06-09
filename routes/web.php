<?php

use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\IntentionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'landing')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Chat home (the daily-driver screen). Named `dashboard` so Fortify's
    // post-login redirect (config/fortify.php → home) lands here. The screen
    // is seeded with the user's active loops as inline action cards.
    Route::get('dashboard', [ChatController::class, 'home'])->name('dashboard');

    // Chat turn: message -> coach reply + inline action cards (JSON). Rate
    // limited per user (the `coach` limiter) since each turn is an LLM call.
    Route::post('chat', [ChatController::class, 'store'])
        ->middleware('throttle:coach')
        ->name('chat');

    // Intentions (loops): the list + detail screens and the write endpoints,
    // all sharing the same Actions as the JSON API.
    Route::resource('intentions', IntentionController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy']);

    // Log an action's outcome (completion / failure + reason).
    Route::post('actions/{action}/logs', [ActionLogController::class, 'store'])
        ->name('actions.logs.store');
});

require __DIR__.'/settings.php';
