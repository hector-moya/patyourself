<div x-data="{ open: $wire.entangle('showSlideover').live }" class="flex relative justify-end">
    {{ $slot }}
</div>