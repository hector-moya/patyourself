<?php

namespace App\Livewire\Exercises;

use App\Livewire\Forms\ExerciseForm;
use App\Models\Exercise;
use Livewire\Component;
use Livewire\Attributes\Computed;

class ExerciseRow extends Component
{
    public Exercise $exercise;
    public ExerciseForm $form;

    protected $listeners = ['exerciseUpdated' => 'setExercise'];

    public function mount()
    {
        $this->setExercise();
    }

    #[Computed]
    public function setExercise()
    {
        $this->form->setExercise($this->exercise);
    }

    public function updated()
    {
        $this->form->update();
        $this->dispatch('exerciseUpdated');
    }
    public function render()
    {
        return view('livewire.exercises.exercise-row');
    }
}
