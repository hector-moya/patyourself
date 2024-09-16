@props([
    'span' => 'col-span-6',
    'label' => null,
    'size' => 'w-10',
])

<div class="{{ $span }} text-start pb-4">
    @if ($label)
        <x-forms.label for="{{ $label }}" />
    @endif

    <div x-data="{ 
        count: 0, 
        updateCount(value) {
            this.count = value            
        } 
    }" class="flex items-center rounded-md dark:bg-gray-700 border border-gray-200 dark:border-gray-300">

        <button @click="count--" class="p-2 text-gray-500 rounded-l-md border dark:border-gray-700 dark:hover:border-gray-600 hover:text-gray-700 dark:hover:bg-gray-600 dark:text-gray-400 dark:hover:text-gray-200">-</button>

        <input @input.debounce.500ms="updateCount($event.target.value)" x-modelable="count" 
            {{ $attributes->merge([
                'class' => 'dark:text-white text-gray-700 dark:bg-gray-800 text-right border-none focus:ring-0 ' . 
                           ($size ?? 'w-10'),
            ]) }} 
        />

        <button @click="count++" class="p-2 text-gray-500 rounded-r-md border dark:border-gray-700 dark:hover:border-gray-600 hover:text-gray-700 dark:hover:bg-gray-600 dark:text-gray-400 dark:hover:text-gray-200">+</button>
    </div>

    <x-forms.error field="{{ $attributes->whereStartsWith('wire:model') }}" />
</div>