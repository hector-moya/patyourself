<x-app-layout>
    <x-slot:header>{{ __('Editing Exercise') }}</x-slot:header>
    <x-slot:subheading>{{ $exercise->name }}</x-slot:subheading>
    <div class="mx-auto max-w-7xl py-12">
        <livewire:exercises.exercise-edit :$exercise @exerciseEdited="$refresh" />
    </div>
</x-app-layout>