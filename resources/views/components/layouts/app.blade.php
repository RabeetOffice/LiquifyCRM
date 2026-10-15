<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($_COOKIE['lq_theme'] ?? '') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) && $title !== '' ? $title.' · ' : '' }}LiquifyCRM</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="LiquifyCRM">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#f4f4f2">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b0a09">
    @auth
        {{-- The public key for desktop alerts with LiquifyCRM closed (Web Push); absent until "Update system". --}}
        @php
            $pushKey = \App\Modules\Core\Support\WebPush::publicKey();
        @endphp
        @if ($pushKey !== null)
            <meta name="lq-push-key" content="{{ $pushKey }}">
        @endif
    @endauth
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
        @include('partials.update-bar')

        {{-- data-live: what this page shows to GET /api/live, which app.js asks every few seconds.
             data-live-at: the brand stamp this page was drawn with. A page fetched earlier (hovered
             link, back button) is reloaded at once when something changed since. --}}
        @php
            $liveUser = auth()->user();
            $liveBrand = app(\App\Modules\Core\Support\CurrentBrand::class)->id();
            $liveEvery = $liveUser instanceof \App\Modules\Auth\Models\User && $liveBrand === null && $liveUser->isGlobalAdmin();
        @endphp
        <main class="lq-content" id="main-content" @if ($liveUser instanceof \App\Modules\Auth\Models\User) data-live="{{ \App\Modules\Core\Support\Live::token((int) $liveUser->getKey(), $liveBrand, $liveEvery) }}" data-live-at="{{ \App\Modules\Core\Support\Live::stamps($liveBrand, (int) $liveUser->getKey(), $liveEvery)['b'] }}" @endif>
            {{ $slot }}
        </main>
    </div>

    <x-ui.toasts />

    @auth
        @livewire(\App\Modules\Core\Livewire\WelcomeTour::class)
    @endauth

    @livewireScriptConfig
</body>
</html>
