<div>
  <flux:heading size="lg">{{ __('My Current Plan') }}</flux:heading>
  <x-stacked-list>
    @foreach ($workouts as $workout)
      <x-stacked-list.list-wrapper :option="$workout">
        <div class="flex min-w-0 items-center gap-x-4 overflow-hidden">
          <x-forms.unsplash class="h-16 w-24 rounded-md object-cover" :photo="$workout->image_path" />
          <div class="min-w-0 flex-auto">
            <flux:heading>
              {{ $workout->name }}
              <flux:badge size="sm" inset="top bottom"><x-healthicons-o-exercise class="mr-1 h-4 w-4" />{{ $workout->category->name }}</flux:badge>
            </flux:heading>
            <flux:subheading>{{ $workout->description }}</flux:subheading>
          </div>
        </div>
        <div class="hidden shrink-0 sm:flex sm:flex-col sm:items-center">
          <flux:button href="{{ route('workouts.show', $workout) }}">View</flux:button>
        </div>
      </x-stacked-list.list-wrapper>
    @endforeach
  </x-stacked-list>
</div>
