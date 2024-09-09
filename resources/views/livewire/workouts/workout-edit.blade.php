<div>
    <div class="flex w-full justify-between">
        <x-forms.label for="{{ __('Edit Workout: ') . $workout->name }}" />
        <x-slideover>
            <x-slideover.open-button>
                <x-button>Add Exercise</x-button>
            </x-slideover.open-button>
            @if ($showSlideover)
                <x-slideover.overlay>
                    <x-slideover.header>
                        <x-forms.label for="All Exercises" />
                    </x-slideover.header>
                    <x-slideover.body>
                        <x-stacked-list>
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
                        </x-stacked-list>
                    </x-slideover.body>
                    <x-slideover.footer />
                </x-slideover.overlay>
            @endif
        </x-slideover>
    </div>
    <x-forms.form submit="editWorkout">
        <x-forms.input name="workout_name" wire:model.blur="form.name" class="w-1/2" />
        <x-forms.textarea name="workout_description" wire:model.blur="form.description" class="w-1/2" />
    </x-forms.form>

    <div class="flex w-full justify-between">
        <x-forms.label for="{{ __('Current Exercises: ') }}" />
    </div>

    <x-stacked-list>
        @foreach ($form->exercises as $exercise)
            <x-stacked-list.list-wrapper>
                <div class="flex min-w-0 gap-x-4">
                    <x-stacked-list.image :option="$exercise" />
                    <div class="min-w-0 flex-auto">
                        <x-stacked-list.text :option="$exercise" />
                    </div>
                </div>
                <div class="hidden shrink-0 sm:flex sm:flex-col sm:items-end">
                    <x-button wire:click="removeExercise({{ $exercise->id }})">Remove Exercise</x-button>
                </div>
            </x-stacked-list.list-wrapper>
        @endforeach
    </x-stacked-list>

    <div class="flex justify-start pt-4 mt-2">
        <a href="{{ route('workouts.show', $workout->id)}}">
            <x-button>Go Back</x-button>
        </a>
    </div>
</div>
