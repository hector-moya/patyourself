<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlanController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $plans = $user->enrolledPlans->all();
        return view('plans.index', [
            'plans' => $plans
        ]);
    }
}
