<x-app-layout>
    <x-slot name="header">{{ __('List of all exercises in the system') }}</x-slot>
    <div class="mx-auto grid max-w-7xl grid-cols-12 px-4 sm:px-6 lg:px-8 space-y-4 gap-4">
        <div class="col-span-12 pt-4">
            <livewire:exercises.components.exercise-panel />
        </div>
    </div>
</x-app-layout>
