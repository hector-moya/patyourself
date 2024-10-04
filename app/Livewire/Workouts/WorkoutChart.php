<?php

namespace App\Livewire\Workouts;

use Livewire\Component;
use App\Models\Workout;
use App\Models\ExerciseSession;
use App\Models\Exercise;
use Carbon\Carbon;
use Illuminate\Support\Collection;


class WorkoutChart extends Component
{
    public Workout $workout;
    public ?Collection $exercises;
    public array $chartData = [];

    public function mount()
    {
        $this->chartData = $this->prepareChartData();
    }

    private function prepareChartData()
    {
        $data = [];
        foreach ($this->exercises as $exercise) {
            $exerciseData = [
                'label' => $exercise->name,
                'data' => $exercise->exerciseSessions()->orderBy('created_at')->pluck('weight', 'created_at')->toArray(),
                'borderColor' => $this->getRandomColor(),
                'fill' => false,
            ];
            array_push($data, $exerciseData);
        }
        return $data;
    }

    private function getRandomColor()
    {
        return '#' . str_pad(dechex(mt_rand(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
    }

    public function render()
    {
        return view('livewire.workouts.workout-chart');
    }
}
