@props([
    'span' => 'col-span-6',
])
<div class="{{ $span }}">
    <x-buk-textarea {{ $attributes->merge(['class' => 'dark:text-white text-gray-700 dark:bg-gray-800 rounded-md']) }}>{{ $slot }}</x-buk-textarea>
</div>
