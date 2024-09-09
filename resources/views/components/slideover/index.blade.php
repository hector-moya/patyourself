<div x-data="{ open: $wire.entangle('showSlideover').live }" class="flex justify-center dark:bg-gray-800">
    {{ $slot }}
</div>