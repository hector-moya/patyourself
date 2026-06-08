<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'landing')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The PatYourSelf app (Coach + Loops). Named `dashboard` so Fortify's
    // post-login redirect (config/fortify.php → home) lands here.
    Route::inertia('dashboard', 'coach')->name('dashboard');

    // Chat home: message -> coach reply + inline action cards (JSON).
    Route::post('chat', [ChatController::class, 'store'])->name('chat');
});

require __DIR__.'/settings.php';
