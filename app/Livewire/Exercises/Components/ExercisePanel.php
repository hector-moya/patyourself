<?php

namespace App\Livewire\Exercises\Components;

use Livewire\Component;
use App\Models\Exercise;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class ExercisePanel extends Component
{
    use WithPagination;

    public function render()
    {        
        return view('livewire.exercises.components.exercise-panel', [
            'exercises' => Exercise::paginate(10),
        ]);
    }



}
