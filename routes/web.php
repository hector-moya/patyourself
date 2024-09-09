<?php

use App\Models\Plan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\WorkoutController;
use App\Http\Controllers\ExerciseController;

Route::get('/', function () {
    return view('index');
});

Route::group(['middleware' => ['auth:sanctum', 'verified']], function ()
{
    Route::get('/plans',                    [PlanController::class, 'index'])->name('plans.index');
    Route::get('/workouts',                 [WorkoutController::class, 'index'])->name('workouts.index');
    Route::get('/workout/{workout}',        [WorkoutController::class, 'show'])->name('workouts.show');
    Route::get('/workout/{workout}/edit',   [WorkoutController::class, 'edit'])->name('workouts.edit');
    Route::get('/workout/create',           [WorkoutController::class, 'create'])->name('workouts.create');
    Route::get('/exercises',                [ExerciseController::class, 'index'])->name('exercises.index');
    Route::get('/exercise/{exercise}',      [ExerciseController::class, 'show'])->name('exercises.show');
    Route::get('/exercise/{exercise}/edit', [ExerciseController::class, 'edit'])->name('exercises.edit');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified',])->group(function () 
{
    Route::get('/dashboard', function () {return view('dashboard.show');})->name('dashboard');
});
