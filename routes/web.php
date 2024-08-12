<?php

use App\Models\Plan;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PlanController;

Route::get('/', function () {
    return view('index');
});

Route::group(['middleware' => ['auth:sanctum', 'verified']], function ()
{
    Route::get('/plans',        [PlanController::class, 'show'])->name('plans.show');
});

Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified',])->group(function () 
{
    Route::get('/dashboard', function () {return view('dashboard');})->name('dashboard');
});
