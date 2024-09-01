<div>
    <div class="">
        <ul role="list" class="grid grid-cols-1 gap-6 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            @foreach ($workouts as $workout)
                <li class="col-span-1 flex flex-col divide-y divide-gray-200 rounded-lg bg-white text-center shadow">
                    <div class="flex flex-1 flex-col p-8">
                        <img class="mx-auto h-32 w-32 flex-shrink-0 rounded-full"
                            src="{{ $workout->image }}"
                            alt="{{ $workout->name }}">
                        <h3 class="mt-6 text-sm font-medium text-gray-900">{{ $workout->name }}</h3>
                        <dl class="mt-1 flex flex-grow flex-col justify-between">
                            <dt class="sr-only">{{ $workout->name }}</dt>
                            <dd class="text-sm text-gray-500">{{ $workout->description}}</dd>
                            <dt class="sr-only">{{ __('Category') }}</dt>
                            <dd class="mt-3">
                                <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">{{$workout->category->name}}</span>
                            </dd>
                        </dl>
                    </div>
                    <div>
                        <div class="-mt-px flex divide-x divide-gray-200">
                            <div class="flex w-0 flex-1">
                                <a href="mailto:janecooper@example.com"
                                    class="relative -mr-px inline-flex w-0 flex-1 items-center justify-center gap-x-3 rounded-bl-lg border border-transparent py-4 text-sm font-semibold text-gray-900">
                                    <x-healthicons-o-exercise class="h-6 w-6"/>
                                    {{ __('Workout') }}
                                </a>
                            </div>
                            <div class="-ml-px flex w-0 flex-1">
                                <a href="tel:+1-202-555-0170"
                                    class="relative inline-flex w-0 flex-1 items-center justify-center gap-x-3 rounded-br-lg border border-transparent py-4 text-sm font-semibold text-gray-900">
                                    <x-healthicons-o-cardiogram-e class="h-6 w-6"/>
                                    {{ __('Edit')}}
                                </a>
                            </div>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</div>
