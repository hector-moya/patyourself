<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;

class WorkoutController extends Controller
{
    public ?Plan $plan = null;


    public function show(Request $request)
    {
        $this->plan = $request->user()->enrolledExcersisePlan->first();

        return view('workouts.show',[
            'plan' => $this->plan
        ]);
    }
}
