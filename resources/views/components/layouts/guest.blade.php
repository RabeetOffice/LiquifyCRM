<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($_COOKIE['lq_theme'] ?? '') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} · LiquifyCRM</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('images/brand/favicon-96.png') }}" type="image/png" sizes="96x96">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/brand/icon-180.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="LiquifyCRM">
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
{{-- Sign-in, password, two-step and invitation pages: the form on one side, the brand's artwork on the other. --}}
<body class="lq-auth">
    <div class="lq-auth-shell">
        <div class="lq-auth-side">
            <header class="lq-auth-top">
                <a href="{{ url('/') }}" class="lq-wordmark" aria-label="LiquifyCRM">
                    <x-ui.logo />
                </a>

                <button
                    type="button"
                    class="lq-btn lq-btn-ghost lq-btn-icon"
                    x-data
                    x-on:click="$store.theme.toggle()"
                    x-bind:title="$store.theme.current === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                    aria-label="Light or dark mode"
                >
                    <x-heroicon-o-sun class="h-5 w-5" x-show="$store.theme.current === 'dark'" x-cloak />
                    <x-heroicon-o-moon class="h-5 w-5" x-show="$store.theme.current !== 'dark'" />
                </button>
            </header>

            <main class="lq-auth-main">
                <div class="lq-auth-panel">
                    {{ $slot }}
                </div>
            </main>

            <footer class="lq-auth-foot">
                <span>© {{ now()->year }} LiquifyCRM</span>
                <nav aria-label="More pages">
                    <a href="{{ route('legal.privacy') }}">Privacy policy</a>
                    <a href="{{ route('legal.data-deletion') }}">Delete your data</a>
                </nav>
            </footer>
        </div>

        {{-- Decoration only: what LiquifyCRM does, drawn as the screens show it. --}}
        <aside class="lq-auth-art" aria-hidden="true">
            <div class="lq-auth-float lq-auth-float-lead">
                <span class="lq-auth-float-icon"><x-heroicon-o-inbox-arrow-down /></span>
                <span class="lq-auth-float-text">
                    <strong>New lead: Sarah Lee</strong>
                    <span>Facebook · just now</span>
                </span>
                <span class="lq-auth-float-dot"></span>
            </div>

            <div class="lq-auth-float lq-auth-float-target">
                <span class="lq-auth-float-label">Target this month</span>
                <span class="lq-auth-float-figure">84%</span>
                <span class="lq-auth-float-bar"><span></span></span>
                <span class="lq-auth-float-label">£18,400 of £22,000</span>
            </div>

            <div class="lq-auth-float lq-auth-float-assigned">
                <span class="lq-auth-float-avatar">AK</span>
                <span>Assigned to Aisha</span>
                <x-heroicon-s-check-circle class="lq-auth-float-tick" />
            </div>

            <div class="lq-auth-pitch">
                <p class="lq-auth-pitch-title">Every lead from every brand, in one calm place.</p>
                <p class="lq-auth-pitch-text">Website forms, Facebook, Messenger and Instagram arrive the moment they are sent, go to the right team, and become sales you can count.</p>
            </div>
        </aside>
    </div>

    <x-ui.toasts />

    @livewireScriptConfig
</body>
</html>
