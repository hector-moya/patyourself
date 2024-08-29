<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class WorkoutController extends Controller
{
    public function show()
    {
        return view('workouts.show');
    }
}
