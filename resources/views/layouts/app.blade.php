<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..600&display=swap" rel="stylesheet">
    <script defer src="https://unpkg.com/@alpinejs/ui@3.14.1-beta.0/dist/cdn.min.js"></script>

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Styles -->
    @livewireStyles
    @bukStyles
    @fluxStyles
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800">
    <x-flux-navigation-menu />
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown>
            <flux:profile avatar="https://fluxui.dev/img/demo/user.png" />

            <flux:menu>
                <flux:menu.item href="{{ route('profile.show') }}" checked>{{ __('Profile') }}</flux:menu.item>
                <flux:menu.separator />
                <flux:menu.item href="{{ route('logout') }}" icon="arrow-right-start-on-rectangle"
                    @click.prevent="$root.submit();">
                    {{ __('Log Out') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:main class="space-y-6">
        <div>
            @isset($header)
                <flux:heading size="xl" level="1">{{ $header }}</flux:heading>
            @endisset

            @isset($subheading)
                <flux:subheading size="lg" class="mb-6">{{ $subheading }}</flux:subheading>
            @endisset
        </div>

        <flux:separator variant="subtle" />

        {{ $slot }}
    </flux:main>
    @stack('modals')
    @livewireScripts
    @bukScripts
    @fluxScripts
</body>

</html>
