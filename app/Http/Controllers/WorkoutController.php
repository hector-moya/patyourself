<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\Workout;

class WorkoutController extends Controller
{
    public ?Plan $plan = null;


    public function index(Request $request)
    {
        $this->plan = $request->user()->enrolledExcersisePlan->first();

        return view('workouts.index',[
            'plan' => $this->plan
        ]);
    }

    public function show(Workout $workout)
    {
        return view('workouts.show',[
            'workout' => $workout,
        ]);
    }

    public function edit(Workout $workout)
    {
        return view('workouts.edit',[
            'workout' => $workout,
        ]);
    }
}
