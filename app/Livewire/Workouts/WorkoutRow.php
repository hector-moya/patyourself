<?php

namespace App\Livewire\Workouts;

use App\Livewire\Forms\ExerciseForm;
use Livewire\Component;
use App\Models\Exercise;

class WorkoutRow extends Component
{
    public Exercise $exercise;
    public ExerciseForm $form;
    public bool $showSlideover = false;

    public function mount()
    {
        $this->mountExercise();
    }

    public function save()
    {
        $this->form->addExerciseSession( $this->form->reps, $this->form->weight);
        $this->mountExercise();
    }

    public function mountExercise()
    {
        $this->form->setExercise($this->exercise);
    }
    public function render()
    {
        return view('livewire.workouts.workout-row');
    }
}
