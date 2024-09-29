<div>
  <flux:tab.group>
    <flux:tabs>
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
      <div class="w-1/2 space-y-6">

        <div class="flex items-center justify-between">
          <flux:heading size="lg">{{ __('Current Exercises: ') }}</flux:heading>
          <flux:modal.trigger name="edit-profile">
            <flux:button>{{ __('Add Exercise') }}</flux:button>
          </flux:modal.trigger>
        </div>
        <div>
          @foreach ($form->exercises as $exercise)
            <div class="flex items-center justify-between py-2">
              <flux:subheading>{{ Str::title($exercise->name) }}</flux:subheading>
              <form wire:submit="removeExercise({{ $exercise->id }})">
                <flux:button size="sm" type="submit">{{ __('Remove') }}</flux:button>
              </form>
            </div>
          @endforeach
        </div>
        <flux:modal name="edit-profile" variant="flyout" class="space-y-6">
          <div>
            <flux:heading size="lg">{{ __('All Exercises') }}</flux:heading>
          </div>
          <flux:separator />
          @foreach ($allExercises as $exercise)
            <div class="flex items-center justify-between">
              <flux:subheading>{{ Str::title($exercise->name) }}</flux:subheading>
              <flux:button wire:click="addExercise({{ $exercise->id }})" size="sm">{{ __('Add Exercise') }}</flux:button>
            </div>
          @endforeach
        </flux:modal>
      </div>
    </flux:tab.panel>
  </flux:tab.group>

  <div class="mt-2 flex justify-start pt-4">
    <flux:button href="{{ route('workouts.show', $workout->id) }}">{{ __('Go Back') }}</flux:button>
  </div>
</div>
