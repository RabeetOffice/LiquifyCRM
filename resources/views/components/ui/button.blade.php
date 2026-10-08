@props(['variant' => 'secondary', 'size' => 'md', 'href' => null, 'type' => 'button', 'icon' => null, 'block' => false, 'navigate' => true])
@php
    $classes = ['lq-btn', 'lq-btn-'.$variant, 'lq-btn-sm' => $size === 'sm', 'lq-btn-block' => $block];
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }} @if ($navigate) wire:navigate @endif>
        @if ($icon) @svg($icon) @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icon) @svg($icon) @endif
        {{ $slot }}
    </button>
@endif
