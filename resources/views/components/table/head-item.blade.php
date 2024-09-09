@props([
    'actionButton' => false,
])

@if (!$actionButton)
    <th scope="col" {{ $attributes->merge(['class' => 'py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white']) }}>
        {{ $slot }}
    </th>
@endif
@if ($actionButton)
    <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
        <span class="sr-only">Edit</span>
    </th>
@endif
