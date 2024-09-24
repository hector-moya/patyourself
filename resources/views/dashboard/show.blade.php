<x-app-layout>
    <x-slot name="header">
        <flux:heading size="xl" level="1">{{ __('Let\'s do Something Awesome Today ') . Auth::user()->name . '!' }}</flux:heading>
    </x-slot>

    <div class="mx-auto max-w-7xl py-12">
        <livewire:overview-panel.index />
    </div>
</x-app-layout>
