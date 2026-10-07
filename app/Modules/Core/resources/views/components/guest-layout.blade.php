@props(['title' => config('app.name')])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="lq-guest">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FF6B2C">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite('resources/css/filament/liquify/theme.css')
    <script>
        // Follow the device setting, with the same manual override the panel uses.
        (function () {
            try {
                var theme = localStorage.getItem('theme') || 'system';
                var dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (dark) document.documentElement.classList.add('dark');
            } catch (e) {}
        })();
    </script>
</head>
<body class="lq-guest-body">
    <main class="lq-guest-main">
        <a href="{{ url('/') }}" class="lq-wordmark" aria-label="LiquifyCRM">
            <img src="{{ asset('images/liquify-mark.svg') }}" alt="" class="lq-wordmark-mark">
            <span>Liquify<span class="lq-wordmark-accent">CRM</span></span>
        </a>

        {{ $slot }}
    </main>
</body>
</html>
