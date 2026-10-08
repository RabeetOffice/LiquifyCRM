<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} · LiquifyCRM</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/apple-touch-icon.png') }}">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f4f4f2">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b0a09">
    <script>
        (function () {
            try {
                var mode = localStorage.getItem('theme') || 'system';
                var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="lq-guest-body">
    <main class="lq-guest-main">
        <a href="{{ url('/') }}" class="lq-wordmark" aria-label="LiquifyCRM">
            <img src="{{ asset('images/liquify-mark.svg') }}" alt="" class="lq-wordmark-mark">
            <span>Liquify<span class="lq-wordmark-accent">CRM</span></span>
        </a>

        {{ $slot }}
    </main>

    <x-ui.toasts />

    @livewireScriptConfig
</body>
</html>
