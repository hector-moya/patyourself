<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
            {{ __('Workouts') }}
        </h2>
    </x-slot>
    <div class="mx-auto grid max-w-7xl grid-cols-12 px-4 sm:px-6 lg:px-8 space-y-4 gap-4">
        <div class="col-span-12 pt-4">
            <livewire:workouts-panel :$plan />
        </div>
    </div>
</x-app-layout>
