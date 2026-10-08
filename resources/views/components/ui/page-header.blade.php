@props(['title', 'subtitle' => null, 'breadcrumbs' => []])
<div class="lq-page-header">
    <div class="min-w-0">
        @if ($breadcrumbs !== [])
            <ol class="lq-breadcrumbs">
                @foreach ($breadcrumbs as $label => $url)
                    <li>
                        @if (is_string($url))
                            <a href="{{ $url }}" wire:navigate>{{ $label }}</a>
                        @else
                            <span>{{ $label }}</span>
                        @endif
                    </li>
                    @unless ($loop->last)
                        <li aria-hidden="true">/</li>
                    @endunless
                @endforeach
            </ol>
        @endif
        <h1 class="lq-page-title">{{ $title }}</h1>
        @if ($subtitle)
            <p class="lq-page-subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="lq-page-actions">{{ $actions }}</div>
    @endisset
</div>
