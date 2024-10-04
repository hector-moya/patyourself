<div>
    <flux:tab.group>
      <flux:tabs>
        <flux:tab name="details">{{ __('Details') }}</flux:tab>
        <flux:tab name="muscles">{{ __('Muscles') }}</flux:tab>
      </flux:tabs>
  
      <flux:tab.panel name="details">
        <div class="w-1/2 space-y-6">
          <flux:input wire:model.blur="form.name" label="Name" />
          <flux:textarea wire:model.blur="form.description" label="Description" rows="6" />
          <flux:select wire:model="form.targetMuscleId" label="Target Muscle" placeholder="Choose target muscle...">
            @foreach ($muscles as $muscle)
              <flux:option value="{{ $muscle->id }}">{{ $muscle->name}}</flux:option>
            @endforeach
          </flux:select>
          <flux:avatar src="{{ $form->image_path}}" label="Image" />
        </div>
      </flux:tab.panel>
      <flux:tab.panel name="muscles">
        <div class="w-1/2 space-y-6">
  
          <div class="flex items-center justify-between">
            <flux:heading size="lg">{{ __('Current Muscles: ') }}</flux:heading>
            <flux:modal.trigger name="edit-profile">
              <flux:button>{{ __('Add Muscle') }}</flux:button>
            </flux:modal.trigger>
          </div>
          <div>
            @foreach ($form->secondaryMuscles as $muscle)
              <div class="flex items-center justify-between py-2">
                <flux:subheading>{{ Str::title($muscle->name) }}</flux:subheading>
                <form wire:submit="removeMuscle({{ $muscle->id }})">
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
            @foreach ($muscles as $muscle)
              <div class="flex items-center justify-between">
                <flux:subheading>{{ Str::title($muscle->name) }}</flux:subheading>
                <flux:button wire:click="addMuscle({{ $muscle->id }})" size="sm">{{ __('Add Muscle') }}</flux:button>
              </div>
            @endforeach
          </flux:modal>
        </div>
      </flux:tab.panel>
    </flux:tab.group>
  
    <div class="mt-2 flex justify-start pt-4">
      <flux:button icon="arrow-uturn-left" href="{{ route('exercises.index') }}">{{ __('Go Back') }}</flux:button>
    </div>
  </div>
  
