<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Showing Exercise: ') . $exercise->name  }}
        </h2>
    </x-slot>
    <div class="mx-auto max-w-7xl py-12">
    </div>
</x-app-layout>
