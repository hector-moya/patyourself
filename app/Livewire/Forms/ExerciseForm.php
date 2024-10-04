<?php

namespace App\Livewire\Forms;

use App\Models\ExerciseSession;
use Livewire\Attributes\Validate;
use Livewire\Form;
use App\Models\Exercise;
use App\Models\ExerciseWorkout;
use Illuminate\Database\Eloquent\Collection;
use App\Models\Muscle;
use Exception;

class ExerciseForm extends Form
{
    public Exercise $exercise;
    public ?Collection $exercises;

    #[Validate('required|min:3')]
    public string $name = '';

    #[Validate('required|min:6')]
    public string $description = '';
    #[Validate('required|image|max:1024')]
    public string $image_path = '';

    #[Validate('required|integer')]
    public ?int $sets;
    #[Validate('required|integer')]
    public ?int $reps;
    #[Validate('required|integer')]
    public ?int $weight;
    #[Validate('required|min:3')]

    public ?Collection $secondaryMuscles;
    public string $targetMuscleId;
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

    public function setExercise(Exercise $exercise) : void
    {
        $this->exercise = $exercise;
        $this->name = $this->exercise->name;
        $this->description = $this->exercise->description;
        $this->image_path = $this->exercise->image_path ?? '';
        $this->targetMuscleId = (string) $this->exercise->target_muscle_id;
        $this->secondaryMuscles = $this->exercise->muscles;
    }



    public function setExerciseWorkout( Exercise $exercise, ExerciseWorkout $exerciseWorkout) : void
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
    
    public function addExerciseSession() : ExerciseSession
    {  
            $this->validate([
                'reps' => 'required|integer',
                'weight' => 'required|integer',
                ]); 
    
            $exerciseSession = $this->exercise->exerciseSession()->create([
                'user_id' => auth()->id(),
                'reps' => $this->reps,
                'weight' => $this->weight,
            ]);
    
            return $exerciseSession;
    }

    public function removeExerciseSession(int $id) : Collection
    {
        $this->exercise->exerciseSession()->find($id)->delete();
        return $this->exercise->exerciseSession;
    }
}
