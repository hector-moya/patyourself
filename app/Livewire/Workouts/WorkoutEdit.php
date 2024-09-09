<?php

namespace App\Livewire\Workouts;

use Livewire\Component;
use App\Models\Workout;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use App\Livewire\Forms\WorkoutForm;

class WorkoutEdit extends Component
{
    public WorkoutForm $form;
    public Workout $workout;
    public ?Collection $exercises;
    public ?Collection $allExercises;
    public bool $showSlideover = false;

    protected $listeners = ['workoutUpdated' => 'setWorkout'];

    public function mount()
    {
        $this->setWorkout();
    }

    public function setWorkout()
    {
        $this->form->setWorkout($this->workout);
    }

    public function addExercise($id)
    {
        $this->form->addExercise($id);
        $this->dispatch('workoutUpdated', $this->form->workout);
    }

    public function removeExercise($id)
    {
        $this->form->removeExercise($id);
        $this->dispatch('workoutUpdated', $this->form->workout);
    }

    public function updated()
    {
        $this->form->update();
        $this->dispatch('workoutUpdated', $this->form->workout);
    }
    public function render() :View
    {
        $this->allExercises = Exercise::with('muscles')->get();
        return view('livewire.workouts.workout-edit');
    }
}