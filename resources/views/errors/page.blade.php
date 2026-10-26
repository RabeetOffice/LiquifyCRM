{{-- One page for every error: the number, what happened in plain words, and a way on. It needs neither the database nor a signed-in person. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · LiquifyCRM</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('images/brand/favicon-96.png') }}" type="image/png" sizes="96x96">
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
<body class="lq-guest-body">
    <main class="lq-guest-main">
        <a href="{{ url('/') }}" class="lq-wordmark" aria-label="LiquifyCRM">
            <x-ui.logo />
        </a>

        <div class="lq-glass lq-errorcard">
            <p class="lq-errorcode" aria-hidden="true">{{ $code }}</p>
            <h1 class="lq-title">{{ $title }}</h1>
            <p class="lq-muted">{{ $text }}</p>

            <div class="lq-errorcard-actions">
                @if ($retry ?? false)
                    @php
                        // A form or a screen's own request (Livewire posts to /livewire-…/update) has an address that
                        // is no page: opened again it only answers 405. Try again on the page the person was on.
                        $from = request()->headers->get('referer');
                        $again = request()->isMethod('GET') ? url()->full() : (is_string($from) && parse_url($from, PHP_URL_HOST) === request()->getHost() ? $from : url('/'));
                    @endphp
                    <a href="{{ $again }}" class="lq-btn lq-btn-primary lq-btn-block" target="_top">Try again</a>
                @else
                    <a href="{{ url('/') }}" class="lq-btn lq-btn-primary lq-btn-block">Go to the start</a>
                @endif
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-block" onclick="history.length > 1 ? history.back() : (location.href = '{{ url('/') }}')">Go back</button>
            </div>
        </div>
    </main>
</body>
</html>
