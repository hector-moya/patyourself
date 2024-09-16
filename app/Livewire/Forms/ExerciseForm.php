<?php

namespace App\Livewire\Forms;

use App\Models\ExerciseSession;
use Livewire\Attributes\Validate;
use Livewire\Form;
use App\Models\Exercise;
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
    public string $sets = '';
    #[Validate('required|numeric')]
    public string $reps = '';
    #[Validate('required|numeric')]
    public string $weight = '';

    public string $image = '';

    public function save() : Exercise
    {
        $this->validate();

        $this->exercise =  Exercise::create([
            'name' => $this->name,
            'description' => $this->description,
            'sets' => $this->sets,
            'reps' => $this->reps,
            'weight' => $this->weight,
            'image' => $this->image ?? '',
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
            'sets' => $this->sets ?? '',
            'reps' => $this->reps ?? '',
            'weight' => $this->weight ?? '',
            'image' => $this->image ?? '',
        ]);

        $this->resetForm();

        return $this->exercise;
    }

    public function resetForm() : void
    {
        $this->reset('name', 'description', 'sets', 'reps', 'weight', 'image');
    }

    public function setExercise(Exercise $exercise) : void
    {
        $this->exercise = $exercise;
        $this->name = $exercise->name;
        $this->description = $exercise->description;
        $this->sets = $exercise->sets ?? '';
        $this->reps = $exercise->reps ?? '';
        $this->weight = $exercise->weight ?? '';
        $this->image = $exercise->image ?? '';
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
