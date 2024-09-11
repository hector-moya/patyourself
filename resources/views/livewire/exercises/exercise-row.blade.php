<tr>
    <x-table.body-item class="pl-4 pr-3">
        <x-forms.input name="exercise-name" wire:model.blur="form.name" />
    </x-table.body-item>
    <x-table.body-item class="px-3">        
        <x-forms.input-number name="exercise-sets" wire:model.live="form.sets"/>
    </x-table.body-item>
    <x-table.body-item class="px-3">
        <x-forms.input-number name="exercise-reps" wire:model.blur="form.reps" />
    </x-table.body-item>
    <x-table.body-item class="px-3">
        <x-forms.input-number name="exercise-weight" wire:model.blur="form.weight" size="w-12" />
    </x-table.body-item>
    <x-table.body-item actionButton="true">
        <a href="{{ route('exercises.edit', $exercise) }}"><x-button>Edit</x-button></a>
    </x-table.body-item>
</tr>