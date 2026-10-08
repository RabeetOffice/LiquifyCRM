@props(['site'])
<span {{ $attributes->class(['lq-site-icon']) }}>
    @if ($site->faviconUrl())
        <img src="{{ $site->faviconUrl() }}" alt="" loading="lazy">
    @else
        <x-heroicon-o-globe-alt />
    @endif
</span>
