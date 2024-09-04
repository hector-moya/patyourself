@props([
    'options' => [],
])
<ul role="list" class="divide-y divide-gray-800">
    @foreach ($options as $option)
        <a href="{{ route('workouts.show', $option) }}">
            <li class="flex dark:hover:bg-gray-700 hover:rounded-lg justify-between gap-x-6 py-5 px-5">
                <div class="flex min-w-0 gap-x-4">
                    <img class="h-12 w-12 flex-none rounded-full bg-gray-800" src="{{ $option->image }}" alt="">
                    <div class="min-w-0 flex-auto">
                        <p class="text-sm font-semibold leading-6 text-white">{{ $option->name }}</p>
                        <p class="mt-1 truncate text-xs leading-5 text-gray-400">{{ $option->description }}</p>
                    </div>
                </div>
                <div class="hidden shrink-0 sm:flex sm:flex-col sm:items-end">
                    <p class="text-sm leading-6 text-white">{{ $option->category->name }}</p>
                </div>
            </li>
        </a>
    @endforeach
</ul>
