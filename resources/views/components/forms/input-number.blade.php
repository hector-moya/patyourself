@props([
    'span' => 'col-span-1',
    'label' => null,
    'size' => 'w-10',
])

<flux:field>
  @if ($label)
    <flux:label>{{ $label }}</flux:label>
  @endif
  <div x-data="{
      count: 0,
      updateCount(value) {
          this.count = value
      }
  }" class="items-center rounded-md border border-gray-200 dark:border-gray-300 dark:bg-gray-700 w-full relative block group/input">

    <button @click="count--" class="rounded-l-md border p-2 text-gray-500 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:bg-gray-600 dark:hover:text-gray-200">-</button>

    <input @input.debounce.500ms="updateCount($event.target.value)" x-modelable="count"
      {{ $attributes->merge([
          'class' => 'dark:text-white text-gray-700 dark:bg-gray-800 text-right border-none focus:ring-0 ' . ($size ?? 'w-10'),
      ]) }} />

    <button @click="count++" class="rounded-r-md border p-2 text-gray-500 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:bg-gray-600 dark:hover:text-gray-200">+</button>
  </div>

  <flux:error name="count" />
</flux:field>
