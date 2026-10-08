@props(['title', 'description' => null, 'icon' => null, 'image' => null])
<div {{ $attributes->class(['lq-empty']) }}>
    @if ($image)
        <img src="{{ $image }}" alt="" class="lq-empty-art" loading="lazy">
    @elseif ($icon)
        <span class="lq-empty-icon">@svg($icon)</span>
    @endif
    <h3 class="lq-empty-title">{{ $title }}</h3>
    @if ($description)
        <p class="lq-empty-desc">{{ $description }}</p>
    @endif
    @isset($actions)
        <div class="lq-empty-actions">{{ $actions }}</div>
    @endisset
</div>
