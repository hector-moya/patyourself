<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Workout') }}
        </h2>
    </x-slot>
    <div class="mx-auto max-w-7xl py-12">
        <livewire:workouts.workout-edit :$workout @workoutEdited="$refresh" />
    </div>
</x-app-layout>