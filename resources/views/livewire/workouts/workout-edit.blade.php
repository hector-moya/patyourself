<div>
    <x-button class="dark:bg-gray-600 dark:hover:bg-gray-400" wire:click="addExercise">Add</x-button.primary>
    <x-forms.label for="{{ __('Exercise list: ') . $workout->name}}" />
    <x-stacked-list>
        @foreach ($exercises as $exercise)
            <x-stacked-list.list-wrapper>
                <div class="flex min-w-0 gap-x-4">
                    <x-stacked-list.image :option="$exercise" />
                    <div class="min-w-0 flex-auto">
                        <x-stacked-list.text :option="$exercise" />
                    </div>
                </div>
                <div class="hidden shrink-0 sm:flex sm:flex-col sm:items-end">
                    <x-stacked-list.category :option="$exercise" />
                    <x-stacked-list.action :option="$exercise" />
                </div>
            </x-stacked-list.list-wrapper>
        @endforeach
    </x-stacked-list>
    <x-forms.label for="All Exercises" />
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
                    {{-- <x-stacked-list.category :option="$exercise" /> --}}
                    <x-stacked-list.action :option="$exercise" />
                </div>
            </x-stacked-list.list-wrapper>
        @endforeach
    </x-stacked-list>
</div>