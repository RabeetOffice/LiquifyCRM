{{-- A small fact above a list: an icon tile, a quiet label and the number. `tone` colours the tile (ok, warn, fail, quiet). --}}
@props(['label', 'value' => null, 'icon' => null, 'tone' => null, 'hint' => null, 'href' => null])
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class(['lq-mini', 'lq-mini-'.$tone => $tone !== null]) }} wire:navigate>
@else
    <div {{ $attributes->class(['lq-mini', 'lq-mini-'.$tone => $tone !== null]) }}>
@endif
    @if ($icon)
        <span class="lq-mini-icon">@svg($icon)</span>
    @endif
    <span class="min-w-0">
        <span class="lq-mini-label">{{ $label }}</span>
        <span class="lq-mini-value" @if ($hint) title="{{ $hint }}" @endif>{{ $value ?? $slot }}</span>
    </span>
@if ($href)
    </a>
@else
    </div>
@endif
