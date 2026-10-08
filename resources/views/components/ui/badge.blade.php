@props(['color' => 'gray', 'icon' => null])
<span {{ $attributes->class(['lq-badge', 'lq-badge-'.$color]) }}>
    @if ($icon) @svg($icon) @endif
    {{ $slot }}
</span>
