<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-appearance="{{ $appearance ?? 'system' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Resolve appearance before styles and application hydration. --}}
        <script>
            (function() {
                const root = document.documentElement;
                const modes = ['light', 'dark', 'system'];
                let appearance = modes.includes(root.dataset.appearance) ? root.dataset.appearance : 'system';
                try {
                    const stored = window.localStorage.getItem('appearance');
                    if (modes.includes(stored)) appearance = stored;
                } catch {
                    // Fall back to the validated cookie or system preference.
                }
                const dark = appearance === 'dark' || (appearance === 'system' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                root.dataset.appearance = appearance;
                root.classList.toggle('dark', dark);
                root.style.colorScheme = dark ? 'dark' : 'light';
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #f6f1e6;
            }

            html.dark {
                background-color: #211f1b;
            }
        </style>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Momento') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
