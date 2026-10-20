{{-- Public reading pages (privacy policy, delete your data): the sign-in look with a wide card of plain text. No sign-in needed. --}}
@props(['title', 'intro' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · LiquifyCRM</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('images/brand/favicon-96.png') }}" type="image/png" sizes="96x96">
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
    @vite(['resources/css/app.css'])
</head>
<body class="lq-guest-body lq-legal-page">
    <main class="lq-legal-main">
        <a href="{{ url('/') }}" class="lq-wordmark" aria-label="LiquifyCRM">
            <x-ui.logo />
        </a>

        <article class="lq-glass lq-legal">
            <header class="lq-legal-head">
                <h1 class="lq-title">{{ $title }}</h1>
                @if ($intro)
                    <p class="lq-muted">{{ $intro }}</p>
                @endif
                <p class="lq-legal-updated">Last updated {{ \Illuminate\Support\Carbon::parse(config('liquify.legal.updated'))->format('j F Y') }}</p>
            </header>

            <div class="lq-legal-body">
                {{ $slot }}
            </div>

            {{-- Inside the card: under it the links would sit on the artwork and be hard to read. --}}
            <nav class="lq-guest-foot lq-legal-foot" aria-label="More pages">
                <a href="{{ route('legal.privacy') }}">Privacy policy</a>
                <a href="{{ route('legal.data-deletion') }}">Delete your data</a>
                <a href="{{ route('login') }}">Sign in</a>
            </nav>
        </article>
    </main>
</body>
</html>
