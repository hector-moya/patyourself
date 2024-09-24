@props([
    'option' => null,
    'label' => 'View',
])
<a class="button dark:text-white" href="{{ route('workouts.show', $option) }}">
    {{ $label }}
</a>