<tr>
    <x-table-workout.body-item>
        {{ $exercise->name }}
    </x-table-workout.body-item>
    <x-table-workout.body-item>
        {{ __('Missing sets: ') . $exercise->sets }}
    </x-table-workout.body-item>
    <x-table-workout.body-item actionButton="true">
        <x-slideover>
            <x-slideover.open-button>
                <x-button>Record</x-button>
            </x-slideover.open-button>
            @if ($showSlideover)
                <x-slideover.overlay>
                    <x-slideover.header>
                        <x-forms.label for="Record Session" />
                    </x-slideover.header>
                    <x-slideover.body>
                        {{-- <x-forms.input label="Name" name="exercise-name" wire:model.blur="form.name" />
                        <x-forms.input-number label="Sets" name="exercise-sets" wire:model.live="form.sets" />
                        <x-forms.input-number name="exercise-reps" wire:model.blur="form.reps" />
                        <x-forms.input-number name="exercise-weight" wire:model.blur="form.weight" size="w-12" /> --}}
                        <x-drawer-action />
                    </x-slideover.body>
                    <x-slideover.footer />
                </x-slideover.overlay>
            @endif
        </x-slideover>
    </x-table-workout.body-item>
</tr>
