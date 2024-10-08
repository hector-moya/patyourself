<?php

namespace App\Livewire\Workouts;

use App\Livewire\Forms\ExerciseForm;
use Livewire\Component;
use App\Models\Exercise;
use App\Models\ExerciseSession;
use App\Models\ExerciseWorkout;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

class WorkoutRow extends Component
{
    public Exercise $exercise;
    public Workout $workout;
    public ExerciseWorkout $exerciseWorkout;
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
        return $this->exerciseSessions = ExerciseSession::where('exercise_id', $this->exercise->id)
            ->whereDate('created_at', now()->toDateString())
            ->get();
    }

    public function save()
    {
        $this->form->addExerciseSession();
        $this->showSlideover = false;
        $this->mountExercise();
    }

    public function deleteExerciseSession($id)
    {
        $this->form->removeExerciseSession($id);
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
        return view('livewire.workouts.workout-row');
    }
}