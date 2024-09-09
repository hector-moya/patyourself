<div>
    <x-table>
        <x-slot:head>
            <x-table.head-item class="pl-4 pr-3">{{ __('Name') }}</x-table.head-item>
            <x-table.head-item class="px-3">{{ __('Description') }}</x-table.head-item>
            <x-table.head-item class="px-3">{{ __('Sets') }}</x-table.head-item>
            <x-table.head-item class="px-3">{{ __('Reps') }}</x-table.head-item>
            <x-table.head-item actionButton="true">
                Actions
            </x-table.head-item>
        </x-slot:head>
        <x-slot:body>
            @foreach ($exercises as $exercise)
                <tr>
                    <x-table.body-item class="pl-4 pr-3">{{ $exercise->name }}</x-table.body-item>
                    <x-table.body-item class="px-3">{{ $exercise->description }}</x-table.body-item>
                    <x-table.body-item class="px-3">{{ $exercise->sets }}</x-table.body-item>
                    <x-table.body-item class="px-3">{{ $exercise->reps }}</x-table.body-item>
                    <x-table.body-item actionButton="true">
                        <a href="{{ route('exercises.edit', $exercise) }}"><x-button>Edit</x-button></a>
                    </x-table.body-item>
                </tr>
            @endforeach
        </x-slot:body>
    </x-table>
</div>
