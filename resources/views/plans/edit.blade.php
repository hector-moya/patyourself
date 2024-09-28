<x-app-layout>
  <x-slot:header>{{ __('Edit: ' . $plan->name) }}</x-slot:header>
  {{-- <x-slot:subheading>{{ __('You rock ' . Auth::user()->name . '! this is the list of improvement plans you\'re currently enrolled into.') }}</x-slot:subheading> --}}
  <div class="p-6">
    <flux:table>
      <flux:columns>
        <flux:column>{{ __('Name') }}</flux:column>
        <flux:column>{{ __('Category') }}</flux:column>
        <flux:column>{{ __('Intensity') }}</flux:column>
        <flux:column></flux:column>
      </flux:columns>
      @foreach ($workouts as $workout)
        <flux:rows>
          <flux:row>
            <flux:cell>{{ $workout->name }}</flux:cell>
            <flux:cell>{{ $workout->category->name }}</flux:cell>
            <flux:cell>{{ Str::title($workout->intensity) }}</flux:cell>
            <flux:cell>
                <flux:button href="{{ route('workouts.edit', $workout) }}">{{ __('Edit') }}</flux:button>
            </flux:cell>
          </flux:row>
        </flux:rows>
      @endforeach
    </flux:table>
  </div>
</x-app-layout>
