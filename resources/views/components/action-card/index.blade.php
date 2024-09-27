<li
    class="col-span-1 flex flex-col overflow-hidden divide-y divide-gray-200 rounded-lg bg-white text-center shadow hover:shadow-xl dark:divide-gray-700 dark:bg-gray-800">
    @isset($header)
        {{ $header }}
    @endisset
    @isset($body)
        <div class="flex flex-1 flex-col p-8 space-y-6 max-h-64">
            {{ $body }}
        </div>
    @endisset
    @isset($footer)
        <div>
            <div class="-mt-px flex divide-x divide-gray-200 dark:divide-gray-700">
                {{ $footer }}
            </div>
        </div>
    @endisset
</li>
