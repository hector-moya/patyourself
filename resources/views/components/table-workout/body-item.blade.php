@props([
    'actionButton' => false,
])
@if (!$actionButton)
    <td {{ $attributes->merge(['class' => 'whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-white sm:pl-0']) }}>
        {{ $slot }}
    </td>
@endif
@if ($actionButton)
    <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-0">
        {{ $slot }}
    </td>
@endif
