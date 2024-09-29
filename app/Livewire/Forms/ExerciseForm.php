<?php

namespace App\Livewire\Forms;

use App\Models\ExerciseSession;
use Livewire\Attributes\Validate;
use Livewire\Form;
use App\Models\Exercise;
use App\Models\ExerciseWorkout;
use Illuminate\Database\Eloquent\Collection;

class ExerciseForm extends Form
{
    public Exercise $exercise;
    public ?Collection $exercises;

    #[Validate('required|min:3')]
    public string $name = '';

    #[Validate('required|min:6')]
    public string $description = '';
    #[Validate('required|numeric')]
    public string $image_path = '';

    public ?int $sets;
    public ?int $reps;
    public ?int $weight;
    public string $intensity = '';

    public function save() : Exercise
    {
        $this->validate();

        $this->exercise =  Exercise::create([
            'name' => $this->name,
            'description' => $this->description,
            'image_path' => $this->image_path ?? '',
        ]);

        $this->resetForm();

        return $this->exercise;
    }

    public function update() : Exercise
    {
        $this->validate();

        $this->exercise->update([
            'name' => $this->name,
            'description' => $this->description,
            'image_path' => $this->image_path ?? '',
        ]);

        $this->resetForm();

        return $this->exercise;
    }

    public function resetForm() : void
    {
        $this->reset('name', 'description', 'sets', 'reps', 'weight', 'image');
    }

    public function setExercise(Exercise $exercise, ExerciseWorkout $exerciseWorkout) : void
    {
        $this->exercise = $exercise;
        $this->name = $exercise->name;
        $this->description = $exercise->description;
        $this->image_path = $exercise->image_path ?? '';
        $this->sets = $exerciseWorkout->sets;
        $this->reps = $exerciseWorkout->reps;
        $this->weight = $exerciseWorkout->weight;
        $this->intensity = $exerciseWorkout->intensity;
    }

    public function setExerciseWorkout( ExerciseWorkout $exerciseWorkout) : void
    {
        $this->sets = $exerciseWorkout->sets;
        $this->reps = $exerciseWorkout->reps;
        $this->weight = $exerciseWorkout->weight;
        $this->intensity = $exerciseWorkout->intensity;
    }

    public function delete() : void
    {
        $this->exercise->delete();
    }

    public function addMuscle(int $muscleId) : Collection
    {
        $this->exercise->muscles()->attach($muscleId);
        return $this->exercise->muscles;
    }

    public function removeMuscle(int $muscleId) : Collection
    {
        $this->exercise->muscles()->detach($muscleId);
        return $this->exercise->muscles;
    }
    
    public function addExerciseSession(string $reps, string $weight) : ExerciseSession
    {
        $this->validate();

        $exerciseSession = $this->exercise->exerciseSession()->create([
            'user_id' => auth()->id(),
            'reps' => $reps,
            'weight' => $weight,
        ]);

        return $exerciseSession;
    }

    public function removeExerciseSession(int $id) : Collection
    {
        $this->exercise->exerciseSession()->find($id)->delete();
        return $this->exercise->exerciseSession;
    }
}
