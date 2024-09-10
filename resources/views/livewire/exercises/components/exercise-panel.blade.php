<div>
    <x-table>
        <x-slot:head>
            <x-table.head-item class="pl-4 pr-3">{{ __('Name') }}</x-table.head-item>
            <x-table.head-item class="px-3">{{ __('Description') }}</x-table.head-item>
            <x-table.head-item class="px-3">{{ __('Sets') }}</x-table.head-item>
            <x-table.head-item class="px-3">{{ __('Reps') }}</x-table.head-item>
            <x-table.head-item class="px-3">{{ __('Weight') }}</x-table.head-item>
            <x-table.head-item actionButton="true">
                Actions
            </x-table.head-item>
        </x-slot:head>
        <x-slot:body>
            @foreach ($this->getAllExercises as $exercise)
                <livewire:exercises.exercise-row :exercise="$exercise" :key="$exercise->id" />
            @endforeach
        </x-slot:body>
    </x-table>
</div>
