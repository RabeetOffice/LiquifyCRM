@props(['title' => null, 'description' => null, 'flush' => false])
<section {{ $attributes->class(['lq-card', 'lq-card-flush' => $flush]) }}>
    @if ($title || isset($actions))
        <header class="lq-card-header">
            <div class="lq-card-header-text">
                @if ($title)
                    <h2 class="lq-card-title">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="lq-card-desc">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="lq-page-actions">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="lq-card-body">{{ $slot }}</div>

    @isset($footer)
        <footer class="lq-card-footer">{{ $footer }}</footer>
    @endisset
</section>
