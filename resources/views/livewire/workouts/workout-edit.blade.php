<div>
  <flux:tab.group>
    <flux:tabs variant="segmented">
      <flux:tab name="details">{{ __('Details') }}</flux:tab>
      <flux:tab name="exercises">{{ __('Exercise') }}</flux:tab>
    </flux:tabs>

    <flux:tab.panel name="details">
      <div class="w-1/2 space-y-6">
        <flux:input wire:model.blur="form.name" label="Name" />
        <flux:textarea wire:model.blur="form.description" label="Description" rows="2" />
        <flux:input wire:model.blur="form.intensity" label="Intensity" />
        <flux:select wire:model="form.category" label="Category" placeholder="Choose category...">
          @foreach ($categories as $category)
            <flux:option>{{ $category->name }}</flux:option>
          @endforeach
        </flux:select>
      </div>
    </flux:tab.panel>
    <flux:tab.panel name="exercises">
      <flux:modal.trigger name="edit-profile">
        <flux:button>{{ __('Add Exercise') }}</flux:button>
      </flux:modal.trigger>

      <flux:modal name="edit-profile" variant="flyout" class="space-y-6">
        <div>
          <flux:heading size="lg">{{ __('All Exercises') }}</flux:heading>
        </div>
        <flux:separator />
        @foreach ($allExercises as $exercise)
          <x-stacked-list.list-wrapper>
            <div class="flex min-w-0 gap-x-4">
              <x-stacked-list.image :option="$exercise" />
              <div class="min-w-0 flex-auto">
                <x-stacked-list.text :option="$exercise" />
              </div>
            </div>
            <div class="hidden shrink-0 sm:flex sm:flex-col sm:items-end">
              <x-button wire:click="addExercise({{ $exercise->id }})">Add Exercise</x-button>
            </div>
          </x-stacked-list.list-wrapper>
        @endforeach
      </flux:modal>
      <div class="flex w-full justify-between">
        <x-forms.label for="{{ __('Current Exercises: ') }}" />
      </div>
      <x-stacked-list>
        @foreach ($form->exercises as $exercise)
          <x-stacked-list.list-wrapper>
            <div class="flex min-w-0 gap-x-4">
              <div class="min-w-0 flex-auto">
                <x-stacked-list.text :option="$exercise" />
              </div>
            </div>
            <form wire:submit="editExercise({{ $exercise->id }})" class="hidden shrink-0 sm:flex sm:flex-col sm:items-end">
              <flux:button type="submit">{{ __('Remove') }}</flux:button>
            </form>
          </x-stacked-list.list-wrapper>
        @endforeach
      </x-stacked-list>
    </flux:tab.panel>
  </flux:tab.group>

  <div class="mt-2 flex justify-start pt-4">
    <flux:button href="{{ route('workouts.show', $workout->id) }}">{{ __('Go Back') }}</flux:button>
  </div>
</div>
