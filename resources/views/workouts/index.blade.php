<x-app-layout>
      <x-slot:header>{{ __('Your Workouts') }}</x-slot:header>
      <x-slot:subheading>{{ __( Auth::user()->name . ' you are currently enrolled in ' . $plan->name . '. ' . 'Do your best!') }}</x-slot:subheading>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
            {{ $plan->name . ' ' . __('Workouts') }}
        </h2>
    </x-slot>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        @foreach ($workouts as $workout)
            <x-action-card>
                <x-slot:header>
                    <x-forms.unsplash class="transition ease-in-out object-cover h-40 hover:scale-105"
                        :photo="$workout->image_path" />
                </x-slot:header>

                <x-slot:body>
                    <div>
                        <flux:heading size="lg">{{ $workout->name }}</flux:heading>
                        <flux:subheading>{{ $workout->description }}</flux:subheading>
                    </div>
                    <div>
                        <flux:badge color="lime">{{ $workout->category->name }}</flux:badge>
                    </div>
                </x-slot:body>

                <x-slot:footer>
                    <x-action-card.action-left href="{{ route('workouts.show', $workout) }}" action="View" />
                    <x-action-card.action-right href="{{ route('workouts.edit', $workout) }}" action="Edit" />
                </x-slot:footer>
            </x-action-card>
        @endforeach
    </div>
</x-app-layout>
