<div x-data="{ open: $wire.entangle('showSlideover').live }" class="flex justify-end">
    {{ $slot }}
</div>