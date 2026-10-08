<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) && $title !== '' ? $title.' · ' : '' }}LiquifyCRM</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/apple-touch-icon.png') }}">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f4f4f2">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b0a09">
    <script>
        // Apply the saved theme before the first paint so nothing flashes.
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
<body
    class="lq-body"
    x-data
    x-bind:class="{ 'lq-collapsed': ! $store.sidebar.open, 'lq-mobile-open': $store.sidebar.mobile }"
>
    @include('partials.sidebar')

    <div class="lq-overlay" x-show="$store.sidebar.mobile" x-on:click="$store.sidebar.closeMobile()" x-cloak></div>

    <div class="lq-main">
        @include('partials.topbar')

        <main class="lq-content" id="main-content">
            {{ $slot }}
        </main>
    </div>

    <x-ui.toasts />

    @livewireScriptConfig
</body>
</html>
