@props(['model', 'title' => null, 'description' => null])
<div
    x-data="{ open: $wire.entangle('{{ $model }}') }"
    x-show="open"
    x-cloak
    x-on:keydown.escape.window="open = false"
    x-on:click.self="open = false"
    class="lq-sheet-backdrop"
>
    <div class="lq-sheet" role="dialog" aria-modal="true" x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" x-trap.noscroll="open">
        <header class="lq-modal-header">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="lq-modal-title">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="lq-modal-desc">{{ $description }}</p>
                @endif
                @isset($heading)
                    {{ $heading }}
                @endisset
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
