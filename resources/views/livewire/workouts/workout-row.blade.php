<tr>
    <x-table-workout.body-item>
        {{ $form->name }}
    </x-table-workout.body-item>
    <x-table-workout.body-item>
        {{ __('Missing sets: ') . $form->sets - $this->getExerciseSessions()->count() }}
        <x-forms.session-buttons :sets="$form->sets" :recordedSets="$this->getExerciseSessions()" />
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
                        <x-drawer-action>
                            <x-slot:media>
                                <x-drawer-action.media :image="$form->image_path" />
                            </x-slot:media>
                            <x-slot:title>
                                <x-drawer-action.title :title="$form->name" :sessions="$form->sets" />
                            </x-slot:title>
                            <x-slot:actions>
                                <x-forms.input-number label="Reps" name="exercise-reps" wire:model="form.reps" />
                                <x-forms.input-number label="Weight" name="exercise-weight" wire:model="form.weight"
                                    size="w-12" />
                                <x-drawer-action.action wire:click="save" />
                            </x-slot:actions>
                            <x-slot:body>
                                <x-drawer-action.body :description="$form->description" :sets="$form->sets" :reps="$form->reps"
                                    :weight="$form->weight" />
                            </x-slot:body>
                        </x-drawer-action>
                    </x-slideover.body>
                </x-slideover.overlay>
            @endif
        </x-slideover>
    </x-table-workout.body-item>
</tr>
