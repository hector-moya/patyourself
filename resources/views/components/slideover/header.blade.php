<!-- Close Button -->
<div class="flex items-start justify-between space-x-2 bg-gray-50 dark:bg-gray-900 p-4 sticky top-0">
    <h2 id="slide-over-heading" class="text-base font-semibold leading-6 text-gray-900">
        {{ $slot }}
    </h2>
    <div class="ml-3 flex h-7 items-center">
        <button type="button"
            class="relative rounded-md bg-white text-gray-400 hover:text-gray-500 focus:ring-2 focus:ring-indigo-500"
             x-on:click="$dialog.close()">
            <span class="absolute -inset-2.5"></span>
            <span class="sr-only">Close panel</span>
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
</div>
