<flux:row>
  <flux:cell>{{ Str::title($form->name) }}</flux:cell>
  <flux:cell>
    {{ __('Missing sets: ') . $form->sets - $this->getExerciseSessions()->count() }}
    <x-forms.session-buttons :sets="$form->sets" :recordedSets="$this->getExerciseSessions()" />
  </flux:cell>
  <flux:cell>
    <x-slideover>
      <x-slideover.open-button>
        <flux:button icon="arrow-right-end-on-rectangle">{{ __('Record') }}</flux:button>
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
              <x-slot:title>
                <flux:label badge="{{ $form->sets - $this->getExerciseSessions()->count() }}">{{ __('Missing Sets: ') }}</flux:label>
              </x-slot:title>
              <x-slot:actions>
                <div class="grid grid-cols-3 items-end gap-4">
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
                  <form wire:submit="save">
                    <flux:button icon="arrow-right-end-on-rectangle" type="submit" >{{ __('Record') }}</flux:button>
                  </form>
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
        </x-slideover.overlay>
      @endif
    </x-slideover>
  </flux:cell>
</flux:row>
