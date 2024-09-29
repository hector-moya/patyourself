<div class="space-y-6">
  <div class="">
    <div>
      @isset($media)
        <div class="relative flex h-56 justify-center bg-white sm:h-72">
          {{ $media }}
        </div>
      @endisset
      <div class="mt-6 px-4 sm:mt-8 sm:flex sm:items-end sm:px-6">
        <div class="sm:flex-1">
          @isset($title)
            <div>
              <div class="flex items-center">
                {{ $title }}
              </div>
            </div>
          @endisset
          @isset($actions)
            <div class="mt-5 flex flex-wrap space-y-3 sm:space-x-3 sm:space-y-0">
              {{ $actions }}
            </div>
          @endisset
        </div>
      </div>
    </div>
  </div>
  @isset($list)
    <flux:separator />
    <div class="space-y-8 px-4 sm:space-y-6 sm:px-6">
      {{ $list }}
    </div>
  @endisset
  @isset($description)
    <flux:separator />
    <div class="space-y-8 px-4 sm:space-y-6 sm:px-6">
      {{ $description }}
    </div>
  @endisset
</div>
