<x-app-layout>
  <x-slot:header>{{ $workout->name }}</x-slot:header>
  <x-slot:subheading>{{ $workout->description }}</x-slot:subheading>
  <div class="space-y-6">
    <div class="flex justify-end">
      <flux:button icon="pencil-square" href="{{ route('workouts.edit', $workout) }}">{{ __('Edit') }}</flux:button>
    </div>
    <flux:table>
      <flux:columns>
        <flux:column>{{ __('Exercise') }}</flux:column>
        <flux:column>{{ __('Sets') }}</flux:column>
        <flux:column></flux:column>
      </flux:columns>

      <flux:rows>
        @foreach ($exercises as $exercise)
          <livewire:workouts.workout-row :$exercise :$workout :key="$exercise->id" />
        @endforeach
      </flux:rows>
    </flux:table>
  </div>
</x-app-layout>
