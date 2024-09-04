<?php

use App\Models\Plan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\WorkoutController;

Route::get('/', function () {
    return view('index');
});

Route::group(['middleware' => ['auth:sanctum', 'verified']], function ()
{
    Route::get('/plans',                  [PlanController::class, 'index'])->name('plans.index');
    Route::get('/workouts',               [WorkoutController::class, 'index'])->name('workouts.index');
    Route::get('/workout/{workout}',      [WorkoutController::class, 'show'])->name('workouts.show');
    Route::get('/workout/{workout}/edit', [WorkoutController::class, 'edit'])->name('workouts.edit');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified',])->group(function () 
{
    Route::get('/dashboard', function () {return view('dashboard.show');})->name('dashboard');
});
