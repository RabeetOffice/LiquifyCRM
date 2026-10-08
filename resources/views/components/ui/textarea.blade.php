@props(['name' => null, 'rows' => 4])
<textarea rows="{{ $rows }}" @if ($name) name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" @endif {{ $attributes->class(['lq-input', 'lq-input-error' => $name && $errors->has($name)]) }}>{{ $slot }}</textarea>
