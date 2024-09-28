@props([
    'option' => [],
])
<li class="flex justify-between gap-x-6 px-5 py-5 hover:rounded-lg items-center">
    {{ $slot }}
</li>
