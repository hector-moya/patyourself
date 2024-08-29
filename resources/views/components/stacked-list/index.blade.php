@props([
    'workouts' => [],
])
<ul role="list" class="divide-y divide-gray-800">
    @foreach ($workouts as $workout)
        <a href="#">
            <li class="flex justify-between gap-x-6 py-5">
                <div class="flex min-w-0 gap-x-4">
                    <img class="h-12 w-12 flex-none rounded-full bg-gray-800" src="{{ $workout->image }}" alt="">
                    <div class="min-w-0 flex-auto">
                        <p class="text-sm font-semibold leading-6 text-white">{{ $workout->name }}</p>
                        <p class="mt-1 truncate text-xs leading-5 text-gray-400">{{ $workout->description }}</p>
                    </div>
                </div>
                <div class="hidden shrink-0 sm:flex sm:flex-col sm:items-end">
                    <p class="text-sm leading-6 text-white">{{ $workout->category->name }}</p>
                </div>
            </li>
        </a>
    @endforeach
</ul>
