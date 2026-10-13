{{-- `tone` gives people outside the team (leads, chat contacts) one of eight soft colours, always the same for a name. --}}
@props(['name' => '', 'src' => null, 'size' => 'md', 'color' => null, 'tile' => null, 'brand' => false, 'tone' => false])
@php
    $initials = collect(explode(' ', trim((string) $name)))->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $toned = $tone && ! $src && ! $brand;
    $toneClass = $toned ? 'lq-tone-'.((crc32(mb_strtolower(trim((string) $name))) % 8) + 1) : null;
    // A brand's own colour rings its mark; `tile` is what the mark sits on, when the brand chose one (Brand::tileColor()).
    $style = $brand ? implode('; ', array_filter([$color ? '--brand-color: '.$color : null, $tile ? '--brand-tile: '.$tile : null])) : '';
@endphp
{{-- A brand with a logo is a light tile in the brand's colour (`lq-avatar-logo`): the logo sits whole, with air around it. --}}
<span {{ $attributes->class(['lq-avatar', 'lq-avatar-sm' => $size === 'sm', 'lq-avatar-xs' => $size === 'xs', 'lq-avatar-lg' => $size === 'lg', 'lq-avatar-brand' => $brand, 'lq-avatar-logo' => $brand && $src, 'lq-avatar-tone' => $toned, $toneClass]) }} @if ($style !== '') style="{{ $style }}" @endif aria-hidden="true">
    @if ($src)
        {{-- A picture that no longer loads (a Messenger picture's address expires) gives way to the initials. --}}
        <img src="{{ $src }}" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.parentNode.classList.remove('lq-avatar-logo'); this.replaceWith(document.createTextNode(@js($initials !== '' ? $initials : '?')))">
    @else
        {{ $initials !== '' ? $initials : '?' }}
    @endif
</span>
