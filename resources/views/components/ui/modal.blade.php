@props(['model', 'title' => null, 'description' => null, 'size' => 'md'])
{{-- Rendered at the end of <body>, so no blurred or animated parent can trap it. --}}
<template x-teleport="body">
    <div
        x-data="{ open: $wire.entangle('{{ $model }}') }"
        x-show="open"
        x-cloak
        x-on:keydown.escape.window="open = false"
        x-on:click.self="open = false"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="lq-modal-backdrop"
    >
        <div class="lq-modal lq-modal-{{ $size }}" role="dialog" aria-modal="true" x-trap.noscroll="open">
            <header class="lq-modal-header">
                <div class="min-w-0">
                    @if ($title)
                        <h2 class="lq-modal-title">{{ $title }}</h2>
                    @endif
                    @if ($description)
                        <p class="lq-modal-desc">{{ $description }}</p>
                    @endif
                </div>
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-on:click="open = false" title="Close">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </header>

            <div class="lq-modal-body">{{ $slot }}</div>

            @isset($footer)
                <footer class="lq-modal-footer">{{ $footer }}</footer>
            @endisset
        </div>
    </div>
</template>
