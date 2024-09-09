<?php

namespace App\Livewire\Workouts\Components;

use Livewire\Component;
use App\Models\Plan;
use Illuminate\Support\Collection;

class WorkoutsPanel extends Component
{

    public ?Plan $plan = null;
    public Collection $workouts;

    public function mount()
    {
        $this->workouts = $this->plan->workouts;
    }

    
    public function render()
    {
        return view('livewire.workouts.components.workouts-panel');
    }
}