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
                                    {{ $head }}
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-800">
                                {{ $body }}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
