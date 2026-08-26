<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\CatchUpController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\IntentionController;
use App\Http\Controllers\OccurrenceLogController;
use App\Http\Controllers\ProgressController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'landing')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The daily-driver screen. Named `dashboard` because Fortify's post-login
    // redirect (config/fortify.php → home) targets that name. Phase 3 repoints
    // this at the Notebook; until then it shows the loop list.
    Route::get('dashboard', [IntentionController::class, 'index'])->name('dashboard');

    // Loops (the Intention model): list, detail and the write endpoints, all
    // sharing the same Actions as the MCP server.
    Route::resource('loops', IntentionController::class)
        ->parameters(['loops' => 'intention'])
        ->only(['index', 'show', 'store', 'update', 'destroy']);

    // Log an action's outcome (completion / failure + reason).
    Route::post('actions/{action}/logs', [ActionLogController::class, 'store'])
        ->name('actions.logs.store');

    // Edit an action's schedule (time + recurrence, or an anchored cue).
    Route::patch('actions/{action}', [ActionController::class, 'update'])->name('actions.update');

    // Catch up on occasions that passed unlogged. Keyed on the occasion rather
    // than the action, so logging Tuesday on Friday records Tuesday and leaves
    // the next-due pointer where it is.
    Route::get('catch-up', [CatchUpController::class, 'index'])->name('catch-up');
    Route::post('occurrences/{occurrence}/logs', [OccurrenceLogController::class, 'store'])
        ->name('occurrences.logs.store');

    // The in-app inbox: delivered cues + read state.
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
    Route::patch('inbox/read-all', [InboxController::class, 'markAllRead'])->name('inbox.read-all');
    Route::patch('inbox/{notification}/read', [InboxController::class, 'markRead'])->name('inbox.read');

    // The progress dashboard: active-loop metric cards (index) and a per-loop
    // drill-in (detail). Read-only aggregation over the loop's own data.
    Route::get('progress', [ProgressController::class, 'index'])->name('progress');
    Route::get('progress/{intention}', [ProgressController::class, 'show'])->name('progress.show');
});

require __DIR__.'/settings.php';
