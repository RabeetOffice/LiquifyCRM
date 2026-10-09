@props(['confirm' => null])
@if ($confirm)
    <template x-teleport="body">
        <div class="lq-modal-backdrop" x-data x-on:keydown.escape.window="$wire.cancelConfirm()" x-on:click.self="$wire.cancelConfirm()">
            <div class="lq-modal lq-modal-sm" role="alertdialog" aria-modal="true">
                <header class="lq-modal-header">
                    <div class="min-w-0">
                        <h2 class="lq-modal-title">{{ $confirm['title'] }}</h2>
                        @if ($confirm['body'])
                            <p class="lq-modal-desc">{{ $confirm['body'] }}</p>
                        @endif
                    </div>
                </header>

                @if ($confirm['password'])
                    <div class="lq-modal-body">
                        <x-ui.field label="Confirm your password" name="confirmPassword" required>
                            <x-ui.input type="password" wire:model="confirmPassword" name="confirmPassword" autocomplete="current-password" wire:keydown.enter="runConfirmed" />
                        </x-ui.field>
                    </div>
                @endif

                <footer class="lq-modal-footer">
                    <x-ui.button variant="ghost" wire:click="cancelConfirm">Cancel</x-ui.button>
                    <x-ui.button :variant="$confirm['tone']" wire:click="runConfirmed" wire:loading.attr="disabled">{{ $confirm['label'] }}</x-ui.button>
                </footer>
            </div>
        </div>
    </template>
@endif
