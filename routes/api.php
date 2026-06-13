<?php

use App\Http\Controllers\Api\ActionController;
use App\Http\Controllers\Api\ActionLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IntentionController;
use App\Http\Controllers\Api\StrategyController;
use Illuminate\Support\Facades\Route;

// All API route names are prefixed `api.` so they never collide with the web
// routes of the same resource — a collision breaks `route:cache` in production.
Route::name('api.')->group(function () {
    // Public credential exchange — throttled to blunt password/token brute force.
    Route::post('/auth/token', [AuthController::class, 'issueToken'])
        ->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'revokeToken']);

        // Intentions (loops) CRUD — same shared Actions as the web side.
        Route::apiResource('intentions', IntentionController::class);

        // Log an action's outcome (completion / failure + reason).
        Route::post('actions/{action}/logs', [ActionLogController::class, 'store'])
            ->name('actions.logs.store');

        // Edit an action's schedule (time + recurrence, or an anchored cue).
        Route::patch('actions/{action}', [ActionController::class, 'update'])->name('actions.update');

        // Versioned strategy timeline for a loop (read-only).
        Route::get('intentions/{intention}/strategies', [StrategyController::class, 'index'])
            ->name('intentions.strategies.index');
    });
});
