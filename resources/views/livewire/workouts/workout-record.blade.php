<div class="bg-gray-900">
    <div class="mx-auto max-w-7xl">
        <div class="bg-gray-900 py-10">
            <div class="px-4 sm:px-6 lg:px-8">
                <div class="sm:flex sm:items-center">
                    <div class="sm:flex-auto">
                        <h1 class="text-base font-semibold leading-6 text-white">{{ $workout->name }}</h1>
                        <p class="mt-2 text-sm text-gray-300">{{ $workout->description }}</p>
                    </div>
                    <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">

                        <a type="button" href="{{ route('workouts.edit', $workout) }}"
                            class="block rounded-md bg-indigo-500 px-3 py-2 text-center text-sm font-semibold text-white hover:bg-indigo-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">
                            Edit Workout
                        </a>
                    </div>
                </div>
                <div class="mt-8 flow-root">
                    <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                            <table class="min-w-full divide-y divide-gray-700">
                                <thead>
                                    <tr>
                                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-white sm:pl-0">Excercise</th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Sets</th>
                                        <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-white">Reps</th>
                                        <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-0">
                                            <span class="sr-only">Edit</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-800">
                                    @foreach ($exercises as $exercise)
                                        <tr>
                                            <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-white sm:pl-0">
                                                {{ $exercise->name }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-300">
                                                {{ $exercise->sets }}
                                            </td>
                                            <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-300">
                                                {{ $exercise->reps }}
                                            </td>
                                            <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-0">
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
                                                                <x-forms.input label="Name" name="exercise-name" wire:model.blur="form.name" />
                                                                <x-forms.input-number label="Sets" name="exercise-sets" wire:model.live="form.sets" />
                                                                <x-forms.input-number name="exercise-reps" wire:model.blur="form.reps" />
                                                                <x-forms.input-number name="exercise-weight" wire:model.blur="form.weight" size="w-12" />
                                                            </x-slideover.body>
                                                            <x-slideover.footer />
                                                        </x-slideover.overlay>
                                                    @endif
                                                </x-slideover>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
