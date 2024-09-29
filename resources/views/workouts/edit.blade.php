<x-app-layout>
    <x-slot:header>{{ __('Edit Workout') }}</x-slot:header>
    <x-slot:subheading>{{ $workout->name }}</x-slot:subheading>
    <div class="mx-auto max-w-7xl py-12">
        <livewire:workouts.workout-edit :$workout @workoutEdited="$refresh" />
    </div>
</x-app-layout>
