<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\Workout;
use Illuminate\Support\Collection;

class WorkoutController extends Controller
{
    public ?Plan $plan;
    public ?Collection $workouts;


    public function index(Request $request)
    {
        $this->plan = $request->user()->enrolledExcersisePlan->first();

        $this->workouts = $this->plan->workouts()->with('category')->get();

        return view('workouts.index',[
            'workouts' => $this->workouts,
            'plan' => $this->plan,
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
