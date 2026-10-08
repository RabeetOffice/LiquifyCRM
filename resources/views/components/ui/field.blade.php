@props(['label' => null, 'name' => null, 'help' => null, 'required' => false, 'for' => null, 'tip' => null])
<div {{ $attributes->class(['lq-field']) }}>
    @if ($label)
        <div class="lq-label-row">
            <label @if ($for ?? $name) for="{{ $for ?? $name }}" @endif @class(['lq-label', 'lq-label-required' => $required])>{{ $label }}</label>
            @if ($tip)
                <x-ui.help :text="$tip" />
            @endif
        </div>
    @endif

    {{ $slot }}

    @if ($name)
        @error($name)
            <p class="lq-error">{{ $message }}</p>
        @enderror
    @endif

    @if ($help)
        <p class="lq-help">{{ $help }}</p>
    @endif
</div>
