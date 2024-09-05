<?php

namespace App\Livewire\Workouts;

use Livewire\Component;
use App\Models\Workout;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Collection;

class WorkoutEdit extends Component
{
    public Workout $workout;
    public ?Collection $exercises;
    public ?Collection $allExercises;

    public function mount()
    {
        $this->exercises = $this->workout->exercises;
        $this->allExercises = Exercise::all();
    }
    public function render()
    {
        return view('livewire.workouts.workout-edit');
    }
}
