@props([
    'description' => '',
    'sets' => 0,
    'reps' => 0,
    'weight' => 0,
])
<dl class="space-y-8 px-4 sm:space-y-6 sm:px-6">
    <div>
        <dt class="text-md font-medium text-gray-500 dark:text-gray-100 sm:w-40 sm:flex-shrink-0 flex justify-start">
            Description
        </dt>
        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-50 sm:col-span-2 text-start text-wrap">
            <ul class="list-disc pl-5">
                @foreach (json_decode($description, true) as $instruction)
                    <li class="leading-relaxed">{{ $instruction }}</li>
                @endforeach
            </ul>
        </dd>
    </div>
    <div>
        <dt class="text-sm font-medium text-gray-500 dark:text-gray-100 sm:w-40 sm:flex-shrink-0 flex justify-start">
            Sets
        </dt>
        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-50 sm:col-span-2 flex justify-start">{{ $sets }}
        </dd>
    </div>
    <div>
        <dt class="text-sm font-medium text-gray-500 dark:text-gray-100 sm:w-40 sm:flex-shrink-0 flex justify-start">
            Reps
        </dt>
        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-50 sm:col-span-2 flex justify-start">{{ $reps }}
        </dd>
    </div>
    <div>
        <dt class="text-sm font-medium text-gray-500 dark:text-gray-100 sm:w-40 sm:flex-shrink-0 flex justify-start">
            Weight
        </dt>
        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-50 sm:col-span-2 flex justify-start">{{ $weight }}
        </dd>
    </div>
</dl>
