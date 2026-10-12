{{-- `tone` gives people outside the team (leads, chat contacts) one of eight soft colours, always the same for a name. --}}
@props(['name' => '', 'src' => null, 'size' => 'md', 'color' => null, 'brand' => false, 'tone' => false])
@php
    $initials = collect(explode(' ', trim((string) $name)))->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $toned = $tone && ! $src && ! $brand;
    $toneClass = $toned ? 'lq-tone-'.((crc32(mb_strtolower(trim((string) $name))) % 8) + 1) : null;
@endphp
<span {{ $attributes->class(['lq-avatar', 'lq-avatar-sm' => $size === 'sm', 'lq-avatar-xs' => $size === 'xs', 'lq-avatar-lg' => $size === 'lg', 'lq-avatar-brand' => $brand, 'lq-avatar-tone' => $toned, $toneClass]) }} @if ($brand && $color) style="--brand-color: {{ $color }}" @endif aria-hidden="true">
    @if ($src)
        <img src="{{ $src }}" alt="">
    @else
        {{ $initials !== '' ? $initials : '?' }}
    @endif
</span>
