{{-- The pages a browser read on the website, newest first; the first line is where it is now. --}}
<div class="lq-trail">
    @forelse ($trail as $view)
        <div class="lq-trail-row">
            <span class="lq-trail-dot @if ($loop->first) lq-trail-dot-now @endif"></span>
            <span class="lq-trail-main">
                <span class="lq-trail-path" title="{{ $view->url }}">{{ $view->path() }}</span>
                @if ($view->title)<span class="lq-trail-title">{{ $view->title }}</span>@endif
            </span>
            <span class="lq-trail-time" title="{{ $view->viewed_at->format('j M Y, H:i') }} UTC">{{ $loop->first ? 'now' : $view->viewed_at->diffForHumans(['short' => true, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</span>
        </div>
    @empty
        <p class="lq-help">No pages noted for this browser yet.</p>
    @endforelse
</div>
