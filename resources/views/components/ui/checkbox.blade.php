@props(['name' => null, 'label' => null])
<label {{ $attributes->only('class')->class(['lq-check']) }}>
    <input type="checkbox" @if ($name) name="{{ $name }}" @endif {{ $attributes->except('class') }}>
    <span>{{ $label ?? $slot }}</span>
</label>
