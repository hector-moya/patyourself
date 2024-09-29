<tr>
    <x-table.body-item class="pl-4 pr-3">
        <flux:input wire:model.live.debounce.500ms="form.name" />
    </x-table.body-item>
    <x-table.body-item actionButton="true">
        <flux:button href="{{ route('exercises.edit', $exercise) }}">{{ __('Edit')}}</flux:button>
    </x-table.body-item>
</tr>
