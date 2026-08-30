<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Same system-preference detection as the app shell, so a link opened
             from the lock screen matches whichever mode the device is already in. --}}
        <script>
            (function() {
                if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-background font-sans antialiased">
        <div class="flex min-h-screen items-center justify-center p-6">
            <div class="w-full max-w-sm rounded-xl border border-border bg-card p-6 text-center shadow-sm">
                <h1 class="font-display text-lg font-semibold text-foreground">
                    This link has expired
                </h1>

                <p class="mt-3 text-sm text-muted-foreground">
                    Reminder links work for seven days. Open the app to log this instead.
                </p>

                <a
                    href="{{ route('dashboard') }}"
                    class="mt-6 inline-flex h-10 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                >
                    Open the app
                </a>
            </div>
        </div>
    </body>
</html>
