<?php

namespace App\Livewire\Workouts;

use App\Livewire\Forms\ExerciseForm;
use Livewire\Component;
use App\Models\Exercise;
use App\Models\ExerciseSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

class WorkoutRow extends Component
{
    public Exercise $exercise;
    public ExerciseForm $form;
    public Collection $exerciseSessions;
    public bool $showSlideover = false;

    public $testExercise;

    public function mount()
    {
        $this->mountExercise();
    }

    public function getExerciseSessions()
    {
        // Get all sessions for this exercise made today
        return $this->exerciseSessions = ExerciseSession::where('exercise_id', $this->exercise->id)
            ->whereDate('created_at', now()->toDateString())
            ->get();
    }

    public function save()
    {
        $this->form->addExerciseSession($this->form->reps, $this->form->weight);
        $this->mountExercise();
    }

    public function deleteExerciseSession($id)
    {
        $this->form->removeExerciseSession($id);
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