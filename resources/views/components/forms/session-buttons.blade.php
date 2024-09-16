@props([
    'sets' => '0',
    'recordedSets' => [],
])
<div class="flex flex-row">
    @for ($i = 0; $i < $sets - $recordedSets->count(); $i++)
        <div wire:click="save" class="h-4 w-4 bg-gray-400 rounded-full mr-1 cursor-pointer border border-gray-800 hover:bg-gray-300">
        </div>
    @endfor
    @for ($i = 0; $i < $recordedSets->count(); $i++)
        <div wire:click="deleteExerciseSession({{ $recordedSets[$i]->id }})" class="h-4 w-4 bg-green-400 rounded-full mr-1 cursor-pointer border border-green-800 hover:bg-green-300">
        </div>
    @endfor
</div>
