<flux:sidebar sticky stashable class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
    <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

    <flux:brand href="{{ route('dashboard') }}" logo="{{ asset('images/patyourselg_logo.png') }}"
        name="{{ __('PatYourSelf') }}" class="px-2 dark:hidden" />
    <flux:brand href="{{ route('dashboard') }}" logo="{{ asset('images/patyourselg_logo.png') }}"
        name="{{ __('PatYourSelf') }}" class="px-2 hidden dark:flex" />

    {{-- <flux:input as="search" variant="filled" placeholder="Search..." icon="magnifying-glass" /> --}}

    <flux:navlist variant="outline">
        <flux:navlist.item icon="home" href="{{ route('dashboard') }}" current>
            {{ __('Dashboard') }}
        </flux:navlist.item>
        <flux:navlist.item icon="inbox" badge="12" href="{{ route('plans.index') }}">
            {{ __('Plans') }}
        </flux:navlist.item>

        <flux:navlist.group expandable heading="Training" class="hidden lg:grid">
            <flux:navlist.item href="{{ route('workouts.index') }}">{{ __('Workouts') }}</flux:navlist.item>
            <flux:navlist.item href="{{ route('exercises.index') }}">{{ __('Exercises') }}</flux:navlist.item>
        </flux:navlist.group>
    </flux:navlist>

    <flux:spacer />

    <flux:navlist variant="outline">
        <flux:navlist.item icon="cog-6-tooth" href="#">Settings</flux:navlist.item>
        <flux:navlist.item icon="information-circle" href="#">Help</flux:navlist.item>
    </flux:navlist>

    <flux:dropdown position="top" align="left" class="max-lg:hidden">
        <flux:profile avatar="https://fluxui.dev/img/demo/user.png" name="{{ Auth::user()->name }}" />

        <flux:menu>
            <flux:menu.item href="{{ route('profile.show') }}" checked>{{ __('Profile') }}</flux:menu.item>

            <flux:menu.separator />

            <flux:menu.item href="{{ route('logout') }}" icon="arrow-right-start-on-rectangle"
                @click.prevent="$root.submit();">{{ __('Log Out') }}</flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</flux:sidebar>
