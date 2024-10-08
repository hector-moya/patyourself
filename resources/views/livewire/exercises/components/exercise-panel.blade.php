<div>
  <flux:table :paginate="$this->exercises">
    <flux:columns>
      <flux:column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')">{{ __('Name')}}</flux:column>
      <flux:column sortable :sorted="$sortBy === 'target_muscle_id'" :direction="$sortDirection" wire:click="sort('target_muscle_id')">{{ __('Muscle')}}</flux:column>
      <flux:column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">{{ __('Created At')}}</flux:column>
      <flux:column sortable :sorted="$sortBy === 'updated_at'" :direction="$sortDirection" wire:click="sort('updated_at')">{{ __('Updated At')}}</flux:column>
    </flux:columns>

    <flux:rows>
      @foreach ($this->exercises as $exercise)
        <flux:row :key="$exercise->id">
          <flux:cell class="flex items-center gap-3">
            <flux:avatar src="{{ $exercise->image_path }}" />
            {{ Str::title($exercise->name) }}
          </flux:cell>

          <flux:cell class="whitespace-nowrap">{{ Str::title($exercise->targetMuscle->name) }}</flux:cell>

          <flux:cell variant="strong">{{ $exercise->created_at }}</flux:cell>
          <flux:cell variant="strong">{{ $exercise->updated_at }}</flux:cell>

          <flux:cell align="right">
            <flux:button variant="ghost" size="sm" inset="top bottom" href="{{ route('exercises.edit', $exercise) }}">{{ __('Edit') }}</flux:button>
          </flux:cell>
        </flux:row>
      @endforeach
    </flux:rows>
  </flux:table>
</div>
