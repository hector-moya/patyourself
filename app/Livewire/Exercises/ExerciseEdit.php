<?php

namespace App\Livewire\Exercises;

use App\Models\Exercise;
use Livewire\Component;
use Livewire\WithPagination;
use App\Livewire\Forms\ExerciseForm;
use Illuminate\Support\Collection;
use App\Models\Muscle;

class ExerciseEdit extends Component
{
    public ExerciseForm $form;
    public Exercise $exercise;
    public ?Collection $muscles;


    protected $listeners = ['exerciseUpdated' => 'setExercise'];

    public function mount()
    {
        $this->setExercise();
    }

    public function setExercise()
    {
        $this->form->setExercise($this->exercise);
    }

    public function addMuscle(int $muscleId)
    {
        $this->form->addMuscle($muscleId);
        $this->dispatch('exerciseUpdated', $this->form->exercise);
    }

    public function removeMuscle(int $muscleId)
    {
        $this->form->removeMuscle($muscleId);
        $this->dispatch('', $this->form->exercise);
    }

    public function updated()
    {
        $this->form->update();
        $this->dispatch('exerciseUpdated', $this->form->exercise);
    }
    public function render()
    {
        $this->muscles = Muscle::all();
        return view('livewire.exercises.exercise-edit', [
            'muscles' => $this->muscles,
        ]);
    }
}
