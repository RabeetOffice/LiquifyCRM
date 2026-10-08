<div>
    @if ($open)
        <div class="lq-modal-backdrop" x-data="{ tip: 0, last: {{ count($tips) - 1 }} }" x-on:keydown.escape.window="$wire.done()">
            <div class="lq-modal lq-modal-sm" role="dialog" aria-modal="true" aria-label="Welcome tour">
                @foreach ($tips as $index => $tip)
                    <div x-show="tip === {{ $index }}" @if ($index > 0) x-cloak @endif>
                        <header class="lq-modal-header">
                            <div class="flex items-start gap-3">
                                <span class="lq-stat-icon flex-none">@svg($tip['icon'])</span>
                                <div class="min-w-0">
                                    <p class="lq-eyebrow">Tip {{ $index + 1 }} of {{ count($tips) }}</p>
                                    <h2 class="lq-modal-title">{{ $tip['title'] }}</h2>
                                </div>
                            </div>
                        </header>
                        <div class="lq-modal-body">
                            <p class="text-sm">{{ $tip['body'] }}</p>
                        </div>
                    </div>
                @endforeach

                <footer class="lq-modal-footer">
                    <x-ui.button variant="ghost" wire:click="done" class="mr-auto">Skip</x-ui.button>
                    <x-ui.button variant="secondary" x-show="tip > 0" x-on:click="tip--">Back</x-ui.button>
                    <x-ui.button variant="primary" x-show="tip < last" x-on:click="tip++">Next</x-ui.button>
                    <x-ui.button variant="primary" x-show="tip === last" x-cloak wire:click="done">Got it</x-ui.button>
                </footer>
            </div>
        </div>
    @endif
</div>
