@props(['name' => null, 'type' => 'text'])
<input type="{{ $type }}" @if ($name) name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" @endif {{ $attributes->class(['lq-input', 'lq-input-error' => $name && $errors->has($name)]) }}>
