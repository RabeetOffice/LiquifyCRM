@props([
    'label',
    'value' => null,
    'placeholder' => '—',
    'icon' => null,
    'description' => null,
    'descriptionColor' => null,
    'descriptionIcon' => null,
    'href' => null,
    'link' => null,
    'hero' => false,
    'delta' => null,
    'up' => null,
])
@php
    $classes = ['lq-stat', 'lq-stat-hero' => $hero];
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }} wire:navigate>
@else
    <div {{ $attributes->class($classes) }}>
@endif
    <div class="lq-stat-head">
        @if ($icon)
            <span class="lq-stat-icon">@svg($icon)</span>
        @endif
        <span class="lq-stat-label">{{ $label }}</span>
    </div>

    <div class="lq-stat-value-row">
        @if ($value !== null && $value !== '')
            <div class="lq-stat-value" x-count>{{ $value }}</div>
        @else
            <div class="lq-stat-value lq-stat-placeholder">{{ $placeholder }}</div>
        @endif

        @if ($delta)
            <span @class(['lq-delta', 'lq-delta-up' => $up === true, 'lq-delta-down' => $up === false])>
                @if ($up === true)
                    <x-heroicon-m-arrow-trending-up />
                @elseif ($up === false)
                    <x-heroicon-m-arrow-trending-down />
                @endif
                {{ $delta }}
            </span>
        @endif
    </div>

    @if ($description)
        <div @class(['lq-stat-sub', 'lq-stat-sub-'.$descriptionColor => $descriptionColor !== null])>
            @if ($descriptionIcon) @svg($descriptionIcon) @endif
            <span>{{ $description }}</span>
        </div>
    @endif

    @if ($href && $link)
        <div class="lq-stat-foot">
            <span class="lq-stat-link">{{ $link }} <x-heroicon-m-arrow-right /></span>
        </div>
    @endif
@if ($href)
    </a>
@else
    </div>
@endif
