@props([
    'action' => null,
    ])
<div class="flex w-0 flex-1 rounded-bl-lg hover:bg-gray-600">
    <a {{ $attributes->whereStartsWith('href') }}
        class="relative -mr-px inline-flex w-0 flex-1 items-center justify-center gap-x-3 rounded-bl-lg border border-transparent py-4 text-sm font-semibold text-gray-900 hover:scale-105 dark:text-gray-50 transition ease-in-out">
        <x-healthicons-o-exercise class="h-6 w-6" />
        {{ $action }}
    </a>
</div>
