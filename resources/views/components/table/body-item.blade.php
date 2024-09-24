@props([
    'actionButton' => false,
])

@if (!$actionButton)
    <td {{ $attributes->merge(['class' => '']) }}>
        {{ $slot }}
    </td>
@endif
@if ($actionButton)
    <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
        {{ $slot }}
    </td>
@endif
