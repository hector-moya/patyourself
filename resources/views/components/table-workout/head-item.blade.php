@props([
    'actionButton' => false,
])

@if (!$actionButton)
    <th scope="col" {{ $attributes->merge(['class' => 'py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-white sm:pl-0']) }}>
        {{ $slot }}
    </th>
@endif
@if ($actionButton)
    <th scope="col" {{ $attributes->merge(['class' => 'relative py-3.5 pl-3 pr-4 sm:pr-0'])}}>
        <span class="sr-only">{{ $slot }}</span>
    </th>
@endif
