<?php

namespace App\Livewire\Exercises\Components;

use Livewire\Component;
use App\Models\Exercise;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

class ExercisePanel extends Component
{
    use WithPagination;
    public $sortBy = 'name';
    public $sortDirection = 'desc';

    public function sort($column) {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function exercises()
    {
        return Exercise::query()
            ->tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
            ->with('targetMuscle')
            ->paginate(15);
    }

    public function render()
    {        
        $exercises = $this->exercises();
        return view('livewire.exercises.components.exercise-panel', [
            'exercises' => $exercises,
        ]);
    }
}
