<?php

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;
use App\Models\Workout;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Collection;

class WorkoutForm extends Form
{
    public Workout $workout;
    public ?Collection $exercises;

    #[Validate('required|min:3')]
    public string $name = '';

    #[Validate('required|min:6')]
    public string $description = '';

    public string $image = '';

    #[Validate('required|integer')]
    public ?int $category_id;

    public function save() : Workout
    {
        $this->validate();

        $this->workout =  Workout::create([
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image ?? '',
            'category_id' => $this->category_id,
        ]);

        $this->resetForm();

        return $this->workout;
    }

    public function update() : Workout
    {
        $this->validate();

        $this->workout->update([
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image ?? '',
            'category_id' => $this->category_id,
        ]);

        $this->resetForm();

        return $this->workout;
    }

    public function resetForm()
    {
        $this->reset('name', 'description', 'image', 'category_id');
    }

    public function setWorkout(Workout $workout)
    {
        $this->workout = $workout;
        $this->name = $workout->name;
        $this->description = $workout->description;
        $this->image = $workout->image;
        $this->category_id = $workout->category_id;
        $this->exercises = $workout->exercises;
    }

    public function delete()
    {
        $this->workout->delete();
    }

    public function addExercise(Exercise $exercise) : Collection
    {
        $this->workout->exercises()->attach($exercise);
        return $this->workout->exercises;
    }

    public function removeExercise(Exercise $exercise) : Collection
    {
        $this->workout->exercises()->detach($exercise);
        return $this->workout->exercises;
    }
}
