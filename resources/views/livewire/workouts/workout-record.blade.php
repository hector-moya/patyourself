<div class="bg-gray-900">
    <x-table-workout :$workout >
        <x-slot:head>
            <x-table-workout.head-item>
                <flux:heading size="lg">
                Exercise
                </flux:heading>
            </x-table-workout.head-item>
            <x-table-workout.head-item>Sets</x-table-workout.head-item>
            <x-table-workout.head-item actionButton>Edit</x-table-workout.head-item>
        </x-slot:head>
        <x-slot:body>
            @foreach ($exercises as $exercise)
                <livewire:workouts.workout-row :exercise="$exercise" :key="$exercise->id" />
            @endforeach
        </x-slot:body>
    </x-table-workout>
</div>
