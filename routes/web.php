<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\IntentionController;
use App\Http\Controllers\ProgressController;
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

    // Edit an action's schedule (time + recurrence, or an anchored cue).
    Route::patch('actions/{action}', [ActionController::class, 'update'])->name('actions.update');

    // The in-app inbox: delivered cues + read state.
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
    Route::patch('inbox/read-all', [InboxController::class, 'markAllRead'])->name('inbox.read-all');
    Route::patch('inbox/{notification}/read', [InboxController::class, 'markRead'])->name('inbox.read');

    // The progress dashboard: active-loop metric cards (index) and a per-loop
    // drill-in (detail). Read-only aggregation over the loop's own data.
    Route::get('progress', [ProgressController::class, 'index'])->name('progress');
});

require __DIR__.'/settings.php';
