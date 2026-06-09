<?php

use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\IntentionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'landing')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The PatYourSelf app (Coach + Loops). Named `dashboard` so Fortify's
    // post-login redirect (config/fortify.php → home) lands here.
    Route::inertia('dashboard', 'coach')->name('dashboard');

    // Chat home: message -> coach reply + inline action cards (JSON).
    Route::post('chat', [ChatController::class, 'store'])->name('chat');

    // Intention (loop) writes. The list/detail screens (Tasks 19–20) own the
    // read views; these writes share the same Actions as the JSON API.
    Route::resource('intentions', IntentionController::class)
        ->only(['store', 'update', 'destroy']);

    // Log an action's outcome (completion / failure + reason).
    Route::post('actions/{action}/logs', [ActionLogController::class, 'store'])
        ->name('actions.logs.store');
});

require __DIR__.'/settings.php';
