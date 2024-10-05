<?php

namespace App\Livewire\Workouts;

use App\Livewire\Forms\ExerciseForm;
use Livewire\Component;
use App\Models\Exercise;
use App\Models\Workout;
use App\Models\ExerciseWorkout;

class ExerciseRow extends Component
{
    public Exercise $exercise;
    public Workout $workout;
    public ExerciseForm $form;

    public function mount()
    {
        $this->mountExercise();
    }

    public function mountExercise()
    {
        $exerciseWorkout = ExerciseWorkout::where('exercise_id', $this->exercise->id)
        ->where('workout_id', $this->workout->id)
        ->first();

        $this->form->setExerciseWorkout($this->exercise, $exerciseWorkout);
    }
    public function render()
    {
        return view('livewire.workouts.exercise-row');
    }
}
