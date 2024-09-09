<?php

namespace App\Http\Controllers;

use App\Models\Exercise;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
    public function index()
    {
        return view('exercises.index');
    }

    public function show(Exercise $exercise)
    {
        return view('exercises.show', [
            'exercise' => $exercise
        ]);
    }

    public function edit(Exercise $exercise)
    {
        return view('exercises.edit', [
            'exercise' => $exercise
        ]);
    }
}
