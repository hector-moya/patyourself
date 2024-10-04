<x-app-layout>
  <x-slot:header>{{ $workout->name }}</x-slot:header>
  <x-slot:subheading>{{ $workout->description }}</x-slot:subheading>
  <div class="mx-auto max-w-7xl py-12">
    <div class="flex justify-end">
      <flux:button icon="pencil-square" href="{{ route('workouts.edit', $workout) }}">{{ __('Edit') }}</flux:button>
    </div>
    <div>
      <flux:tab.group>
        <flux:tabs>
          <flux:tab name="record_session">{{ __('Record Session') }}</flux:tab>
          <flux:tab name="progession">{{ __('Progression') }}</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="record_session" class="space-y-6">
          {{-- <div class="grid grid-cols-3 gap-6">
            <x-card-stats />
          </div> --}}
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
        </flux:tab.panel>

        <flux:tab.panel name="progession">

        <livewire:workouts.workout-chart :$workout :$exercises />

        </flux:tab.panel>
      </flux:tab.group>
    </div>

  </div>
</x-app-layout>
