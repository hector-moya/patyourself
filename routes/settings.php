<?php

use App\Http\Controllers\Settings\NotificationsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TimezoneController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/timezone', [TimezoneController::class, 'edit'])->name('timezone.edit');
    Route::patch('settings/timezone', [TimezoneController::class, 'update'])->name('timezone.update');

    Route::get('settings/notifications', [NotificationsController::class, 'edit'])->name('notifications.edit');
    Route::patch('settings/notifications', [NotificationsController::class, 'update'])->name('notifications.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // The handle on the export door. A static page — the record itself is
    // streamed by ExportController, and this screen only points at it — so it
    // needs no controller. It sits in this group rather than the one above
    // because `export.show` is gated on `verified` too, and a page whose only
    // two links answer 403 would be worse than no page.
    Route::inertia('settings/record', 'settings/record')->name('record.edit');
});
