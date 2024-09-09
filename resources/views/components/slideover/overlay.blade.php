<!-- Slideover -->
<div x-dialog x-model="open" style="display: none" class="fixed inset-0 z-10 overflow-hidden">
    <!-- Overlay -->
    <div x-dialog:overlay x-transition.opacity class="fixed inset-0 bg-black bg-opacity-50"></div>

    <!-- Panel -->
    <div class="fixed inset-y-0 right-0 w-full max-w-lg">
        <div x-dialog:panel x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-300" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" class="h-full w-full">
            <div class="flex h-full flex-col justify-between overflow-y-auto bg-white dark:bg-gray-800 shadow-lg">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
