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
    public ExerciseWorkout $exerciseWorkout;
    public bool $showSlideover = false;

    public function mount()
    {
        $this->mountExercise();
    }

    public function save()
    {
        $this->form->updateExerciseWorkout($this->exerciseWorkout);
        $this->showSlideover = false;
    }

    public function mountExercise()
    {
        $this->exerciseWorkout = ExerciseWorkout::where('exercise_id', $this->exercise->id)
        ->where('workout_id', $this->workout->id)
        ->first();

        $this->form->setExerciseWorkout($this->exercise, $this->exerciseWorkout);
    }
    public function render()
    {
        return view('livewire.workouts.exercise-row');
    }
}
