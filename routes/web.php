<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\CatchUpController;
use App\Http\Controllers\CompanionController;
use App\Http\Controllers\ExperimentController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\IntentionController;
use App\Http\Controllers\NotebookController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\OccurrenceLogController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\VerdictController;
use App\Models\Intention;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'landing')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The daily-driver screen: what is due in the user's local day, and which
    // experiment is waiting on a verdict. Named `dashboard` because Fortify's
    // post-login redirect (config/fortify.php → home) targets that name — the
    // name must survive any future move.
    Route::get('dashboard', [NotebookController::class, 'index'])->name('dashboard');

    // Loops (the Intention model): list, detail and the write endpoints, all
    // sharing the same Actions as the MCP server.
    Route::resource('loops', IntentionController::class)
        ->parameters(['loops' => 'intention'])
        ->only(['index', 'show', 'store', 'update', 'destroy']);

    // Answering the review the dashboard already surfaces. Keyed on the strategy
    // version, because the version is what carries the verdict.
    Route::post('strategies/{strategy}/verdict', [VerdictController::class, 'store'])
        ->name('strategies.verdict.store');

    // Starting the next version. Append-only: StartExperiment supersedes the
    // current version rather than editing it.
    Route::post('loops/{intention}/experiments', [ExperimentController::class, 'store'])
        ->name('loops.experiments.store');

    // A note is an observation that is not an outcome. Append-only: no edit,
    // no delete.
    Route::post('loops/{intention}/notes', [NoteController::class, 'store'])
        ->name('loops.notes.store');

    // The action layer, editable between experiments. `destroy` archives —
    // see ActionController::destroy for why the verb and the write differ.
    Route::post('loops/{intention}/actions', [ActionController::class, 'store'])
        ->name('loops.actions.store');
    Route::delete('actions/{action}', [ActionController::class, 'destroy'])
        ->name('actions.destroy');

    // Log an action's outcome (completion / failure + reason).
    Route::post('actions/{action}/logs', [ActionLogController::class, 'store'])
        ->name('actions.logs.store');

    // Edit an action's schedule (time + recurrence, or an anchored cue).
    Route::patch('actions/{action}', [ActionController::class, 'update'])->name('actions.update');

    // Catch up on occasions that passed unlogged. Keyed on the occasion rather
    // than the action, so logging Tuesday on Friday dates the outcome by the
    // occasion it describes and leaves every other occasion untouched.
    Route::get('catch-up', [CatchUpController::class, 'index'])->name('catch-up');
    Route::post('occurrences/{occurrence}/logs', [OccurrenceLogController::class, 'store'])
        ->name('occurrences.logs.store');

    // Blob: what the record has grown, and when each part of it arrived. A read
    // over the existing tables — there is nothing companion-shaped in the
    // database to fetch.
    Route::get('companion', [CompanionController::class, 'index'])->name('companion');

    // The in-app inbox: delivered cues + read state.
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox');
    Route::patch('inbox/read-all', [InboxController::class, 'markAllRead'])->name('inbox.read-all');
    Route::patch('inbox/{notification}/read', [InboxController::class, 'markRead'])->name('inbox.read');

    // The progress dashboard: active-loop metric cards (index) and a per-loop
    // drill-in (detail). Read-only aggregation over the loop's own data.
    Route::get('progress', [ProgressController::class, 'index'])->name('progress');
    // The per-loop drill-in folded into the lab record, which now carries the
    // experiment, its evidence and the reflection on one screen. The route name
    // survives so nothing that generates the URL breaks and no bookmark 404s.
    Route::get('progress/{intention}', function (Intention $intention) {
        // Authorized here rather than left to the redirect target. Without it a
        // stranger's loop answers 302 instead of 403 — the lab record still
        // refuses them, but the refusal should happen at the door.
        Gate::authorize('view', $intention);

        return redirect()->route('loops.show', $intention);
    })->name('progress.show');
});

require __DIR__.'/settings.php';
