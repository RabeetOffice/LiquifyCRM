{{-- One page for every error: the number, what happened in plain words, and a way on. It needs neither the database nor a signed-in person. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · LiquifyCRM</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
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
            <img src="{{ asset('images/liquify-mark.svg') }}" alt="" class="lq-wordmark-mark">
            <span>Liquify<span class="lq-wordmark-accent">CRM</span></span>
        </a>

        <div class="lq-glass lq-errorcard">
            <p class="lq-errorcode" aria-hidden="true">{{ $code }}</p>
            <h1 class="lq-title">{{ $title }}</h1>
            <p class="lq-muted">{{ $text }}</p>

            <div class="lq-errorcard-actions">
                @if ($retry ?? false)
                    <a href="{{ url()->current() }}" class="lq-btn lq-btn-primary lq-btn-block">Try again</a>
                @else
                    <a href="{{ url('/') }}" class="lq-btn lq-btn-primary lq-btn-block">Go to the start</a>
                @endif
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-block" onclick="history.length > 1 ? history.back() : (location.href = '{{ url('/') }}')">Go back</button>
            </div>
        </div>
    </main>
</body>
</html>
