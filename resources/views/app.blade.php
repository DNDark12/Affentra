<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ cookie('theme', 'light') === 'dark' ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Affentra') }}</title>

        <script>
            (() => {
                try {
                    const cookieMatch = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
                    const cookieTheme = cookieMatch ? decodeURIComponent(cookieMatch[1]) : null;
                    const localTheme = localStorage.getItem('theme');
                    const theme = localTheme || cookieTheme;

                    if (theme === 'dark') {
                        document.documentElement.classList.add('dark');
                    } else if (theme === 'light') {
                        document.documentElement.classList.remove('dark');
                    }
                } catch (_) {
                    // no-op
                }
            })();
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:300,400,500,600,700&display=swap" rel="stylesheet" />

        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
