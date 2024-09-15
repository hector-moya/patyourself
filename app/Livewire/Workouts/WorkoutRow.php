<?php

namespace App\Livewire\Workouts;

use Livewire\Component;
use App\Models\Exercise;

class WorkoutRow extends Component
{
    public Exercise $exercise;
    public bool $showSlideover = false;
    public function render()
    {
        return view('livewire.workouts.workout-row');
    }
}
