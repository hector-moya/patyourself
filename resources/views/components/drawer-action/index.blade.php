<div>
    <div class="pb-1 sm:pb-6">
        <div>
            <div class="relative h-40 sm:h-56">
                {{ $media }}
            </div>
            <div class="mt-6 px-4 sm:mt-8 sm:flex sm:items-end sm:px-6">
                <div class="sm:flex-1">
                    <div>
                        <div class="flex items-center">
                            {{ $title }}
                        </div>
                    </div>
                    <div class="mt-5 flex flex-wrap space-y-3 sm:space-x-3 sm:space-y-0">
                        {{ $actions }}
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="px-4 pb-5 pt-5 sm:px-0 sm:pt-0">
        {{ $body }}
    </div>
</div>
