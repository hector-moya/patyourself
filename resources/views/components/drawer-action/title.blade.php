@props([
    'title' => '',
    'sessions' => 0,
])
<h3 class="text-xl font-bold text-gray-900 dark:text-gray-50 sm:text-2xl">{{ $title }}</h3>
<div class="ml-2.5 items-center inline-block h-6 w-6 flex-shrink-0 rounded-lg bg-gray-700">
    <p class="text-center items-center text-white">{{ $sessions }}</p>
</div>
