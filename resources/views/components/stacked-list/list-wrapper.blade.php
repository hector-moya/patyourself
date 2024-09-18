@props([
    'option' => [],
])
<li class="flex justify-between gap-x-6 px-5 py-5 hover:rounded-lg dark:hover:bg-gray-700 cursor-pointer" wire:click="redirectTo({{$option->id}})">
    {{ $slot }}
</li>
