  <x-app-layout>
      <x-slot:header>{{ __('Your Plans') }}</x-slot:header>
      <x-slot:subheading>{{ __('You rock ' . Auth::user()->name . '! this is the list of improvement plans you\'re currently enrolled into.') }}</x-slot:subheading>
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 ">
          @foreach ($plans as $plan)
              <x-action-card>
                  <x-slot:header>
                      <x-forms.unsplash class="transition ease-in-out object-cover h-40 hover:scale-105"
                          :photo="$plan->image_path" />
                  </x-slot:header>

                  <x-slot:body>
                      <div>
                          <flux:heading size="lg">{{ $plan->name }}</flux:heading>
                          <flux:subheading>{{ $plan->description }}</flux:subheading>
                      </div>
                      <div>
                          <flux:badge color="lime">{{ $plan->objective->name }}</flux:badge>
                      </div>
                  </x-slot:body>

                  <x-slot:footer>
                      <x-action-card.action-left href="{{ route('workouts.index') }}" action="View" />
                      <x-action-card.action-right href="{{ route('plans.edit', $plan) }}" action="Edit" />
                  </x-slot:footer>
              </x-action-card>
          @endforeach
      </div>
  </x-app-layout>
