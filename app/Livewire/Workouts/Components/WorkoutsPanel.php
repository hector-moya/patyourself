<?php

namespace App\Livewire\Workouts\Components;

use Livewire\Component;
use App\Models\Plan;
use Illuminate\Support\Collection;

class WorkoutsPanel extends Component
{

    public ?Plan $plan;
    public Collection $workouts;

    public function mount()
    {
        $this->workouts = $this->plan->workouts()->with('category')->get();
    }

    
    public function render()
    {
        return view('livewire.workouts.components.workouts-panel');
    }
}
