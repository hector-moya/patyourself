@props([
    'span' => 'col-span-6',
    'label' => null,
])

<div class="{{ $span }}">
    @if ($label)
        <x-forms.label for="{{ $label }}" />
    @endif
    <x-buk-input {{ $attributes->merge(['class' => 'dark:text-white text-gray-700 dark:bg-gray-800 rounded-md']) }} />
    <x-forms.error field="{{ $attributes->whereStartsWith('wire:model') }}" />
</div>
