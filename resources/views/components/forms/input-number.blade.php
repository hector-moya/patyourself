@props([
    'span' => 'col-span-6',
    'label' => null,
])

<div class="{{ $span }}">
    @if ($label)
        <x-forms.label for="{{ $label }}" />
    @endif

    <div x-data="{ 
        value: ,
        increment() { this.value++; },
        decrement() { this.value--; }
     }" class="flex items-center border border-gray-300 rounded-md dark:border-gray-600">

        <button @click="decrement" class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">-</button>

        <input 
            x-model.number="value" 
            type="number"
            {{ $attributes->merge([
                'class' => 'dark:text-white text-gray-700 dark:bg-gray-800 w-16 text-center border-none focus:ring-0',
            ]) }} 
        />

        <button @click="increment" class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">+</button>
    </div>

    <x-forms.error field="{{ $attributes->whereStartsWith('wire:model') }}" />
</div>