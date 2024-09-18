<div>
    <x-forms.label for="My_Current_Plan" />
    <x-stacked-list>
        @foreach ($workouts as $workout)
            <x-stacked-list.list-wrapper :option="$workout">
                    <div class="flex min-w-0 gap-x-4">
                        <x-stacked-list.image :option="$workout" />
                        <div class="min-w-0 flex-auto">
                            <x-stacked-list.text :option="$workout" />
                        </div>
                    </div>
                    <div class="hidden shrink-0 sm:flex sm:flex-col sm:items-end">
                        <x-stacked-list.category :option="$workout" />
                        <x-stacked-list.action :option="$workout" />
                    </div>
            </x-stacked-list.list-wrapper>
        @endforeach
    </x-stacked-list>
</div>
