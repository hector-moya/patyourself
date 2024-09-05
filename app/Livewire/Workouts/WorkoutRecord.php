<?php

namespace App\Livewire\Workouts;

use Livewire\Component;
use App\Models\Workout;

class WorkoutRecord extends Component
{
    public Workout $workout;

    public $exercises;

    public function mount()
    {
        $this->exercises = $this->workout->exercises;
    }
    public function render()
    {
        return view('livewire.workouts.workout-record');
    }
}
