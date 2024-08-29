<?php

namespace App\Livewire\OverviewPanel;

use Livewire\Component;
use App\Models\Workout;

class Index extends Component
{
    public $workouts;

    public function mount()
    {
        $this->workouts = Workout::all();
    }
    public function render()
    {
        return view('livewire.overview-panel.index');
    }
}
