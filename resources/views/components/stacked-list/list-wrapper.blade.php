@props([
    'option' => [],
])
<a href="{{ route('workouts.show', $option) }}">
    <li class="flex justify-between gap-x-6 px-5 py-5 hover:rounded-lg dark:hover:bg-gray-700 cursor-pointer">
        {{ $slot }}
    </li>
</a>
