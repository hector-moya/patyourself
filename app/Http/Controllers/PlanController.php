<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Plan;
use Illuminate\Support\Collection;

class PlanController extends Controller
{
    public ?Plan $plan;
    public ?Collection $workouts;
    public function index()
    {
        $user = Auth::user();
        $plans = $user->enrolledPlans->all();
        return view('plans.index', [
            'plans' => $plans
        ]);
    }

    public function edit(Plan $plan)
    {
        $this->workouts = $plan->workouts()->with('category')->get();

        return view('plans.edit', [
            'plan' => $plan,
            'workouts' => $this->workouts,
        ]);
    }
}
