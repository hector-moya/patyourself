<?php

namespace App\Livewire\Exercises\Components;

use Livewire\Component;
use App\Models\Exercise;
use Illuminate\Support\Collection;

class ExercisePanel extends Component
{
    public ?Collection $exercises;


    public function render()
    {
        $this->exercises = Exercise::all();
        
        return view('livewire.exercises.components.exercise-panel', [
            'exercises' => $this->exercises
        ]);
    }
}
