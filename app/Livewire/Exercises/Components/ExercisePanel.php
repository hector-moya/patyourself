<?php

namespace App\Livewire\Exercises\Components;

use Livewire\Component;
use App\Models\Exercise;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

class ExercisePanel extends Component
{
    #[Computed]
    public function getAllExercises() : Collection
    {
        return Exercise::all();
    }

    public function render()
    {        
        return view('livewire.exercises.components.exercise-panel');
    }



}
