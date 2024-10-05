<div class="flex justify-between pb-6 space-x-6 items-center">
  <flux:subheading class="grow">{{ Str::title($exercise->name) }}</flux:subheading>
  <form wire:submit="removeExercise({{ $exercise->id }})">
    <flux:button type="submit">{{ __('Remove') }}</flux:button>
  </form>
  <x-slideover>
    <x-slideover.open-button>
      <flux:button icon="arrow-right-end-on-rectangle">{{ __('Edit') }}</flux:button>
    </x-slideover.open-button>
    @if ($showSlideover)
      <x-slideover.overlay>
        <x-slideover.header>
          <flux:heading size="lg">{{ __('Record ' . Str::title($form->name) . ' Session') }}</flux:heading>
        </x-slideover.header>
        <x-slideover.body>
          <x-drawer-action>
            <x-slot:media>
              <x-drawer-action.media :image="$form->image_path" />
            </x-slot:media>
            <x-slot:actions>
              <div class="grid grid-cols-1 xl:grid-cols-3 items-end gap-4">
                <x-forms.input-number wire:model="form.sets" size="w-12">
                  <x-slot:label>
                    <flux:label badge="{{ $form->sets }}">{{ __('Sets') }}</flux:label>
                  </x-slot:label>
                </x-forms.input-number>
                <x-forms.input-number wire:model="form.reps" size="w-12">
                  <x-slot:label>
                    <flux:label badge="{{ $form->reps }}">{{ __('Reps') }}</flux:label>
                  </x-slot:label>
                </x-forms.input-number>
                <x-forms.input-number wire:model="form.weight" size="w-12">
                  <x-slot:label>
                    <flux:label badge="{{ $form->weight }}">{{ __('Weight') }}</flux:label>
                  </x-slot:label>
                </x-forms.input-number>
              </div>
            </x-slot:actions>
            <x-slot:description>
              <flux:label>{{ __('Description') }}</flux:label>
              <div class="space-y-2">
                @foreach (json_decode($form->description, true) as $instruction)
                  <flux:subheading size="md" class="text-wrap">{{ $instruction }}</flux:subheading>
                @endforeach
              </div>
            </x-slot:description>
          </x-drawer-action>
        </x-slideover.body>
        <x-slideover.footer>
            <form wire:submit="save">
              <flux:button icon="arrow-right-end-on-rectangle" type="submit" >{{ __('Save') }}</flux:button>
            </form>
        </x-slideover.footer>
      </x-slideover.overlay>
    @endif
  </x-slideover>
</div>
