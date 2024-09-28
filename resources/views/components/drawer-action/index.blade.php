<div class=" space-y-6">
    <div class="">
        <div>
            <div class="relative h-56 sm:h-72 flex justify-center bg-white">
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
    <flux:separator />
    <div class="space-y-8 px-4 sm:space-y-6 sm:px-6">
        {{ $list}}
    </div>
    <flux:separator />
    <div class="space-y-8 px-4 sm:space-y-6 sm:px-6">
        {{ $description }}
    </div>
</div>
