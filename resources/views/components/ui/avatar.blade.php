@props(['name' => '', 'src' => null, 'size' => 'md', 'color' => null, 'brand' => false])
@php
    $initials = collect(explode(' ', trim((string) $name)))->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
@endphp
<span {{ $attributes->class(['lq-avatar', 'lq-avatar-sm' => $size === 'sm', 'lq-avatar-lg' => $size === 'lg', 'lq-avatar-brand' => $brand]) }} @if ($brand && $color) style="--brand-color: {{ $color }}" @endif aria-hidden="true">
    @if ($src)
        <img src="{{ $src }}" alt="">
    @else
        {{ $initials !== '' ? $initials : '?' }}
    @endif
</span>
