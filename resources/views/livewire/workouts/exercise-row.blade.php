<div class="flex justify-between pb-6 space-x-6 items-center">
  <flux:subheading class="grow">{{ Str::title($exercise->name) }}</flux:subheading>
  <x-forms.input-number wire:model="form.sets" size="w-12" />
  <x-forms.input-number wire:model="form.reps" size="w-12" />
  <x-forms.input-number wire:model="form.weight" size="w-12" />
  <form wire:submit="removeExercise({{ $exercise->id }})">
    <flux:button type="submit">{{ __('Remove') }}</flux:button>
  </form>
</div>
