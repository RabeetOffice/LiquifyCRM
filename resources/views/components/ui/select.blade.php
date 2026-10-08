@props(['name' => null, 'placeholder' => null])
<select @if ($name) name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" @endif {{ $attributes->class(['lq-input', 'lq-input-error' => $name && $errors->has($name)]) }}>
    @if ($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
    @endif
    {{ $slot }}
</select>
