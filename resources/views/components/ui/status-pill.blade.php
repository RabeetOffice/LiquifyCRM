@props(['status'])
<span {{ $attributes->class(['lq-status-pill']) }} style="--pill-color: {{ $status->color }}; --pill-tint: {{ $status->tint }}">{{ $status->name }}</span>
