<?php

namespace App\Livewire\OverviewPanel;

use Livewire\Component;
use App\Models\Workout;
use Illuminate\Support\Facades\Auth;

class Index extends Component
{
    public $workouts;

    public function mount()
    {
        $this->workouts = Auth::user()->enrolledExcersisePlan->first()->workouts;
    }
    public function render()
    {
        return view('livewire.overview-panel.index');
    }
}
