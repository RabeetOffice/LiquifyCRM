<x-layouts.guest title="Two-factor code">
    <div class="lq-glass" x-data="{ recovery: false }">
        <div class="lq-card-header-stack">
            <h1 class="lq-title">Enter your code</h1>
            <p class="lq-muted" x-show="! recovery">Open your authenticator app and type the 6-digit code.</p>
            <p class="lq-muted" x-show="recovery" x-cloak>Enter one of the recovery codes you saved when you set up two-factor authentication.</p>
        </div>

        <form method="POST" action="{{ route('two-factor.login') }}" class="lq-form">
            @csrf

            <div x-show="! recovery">
                <x-ui.field label="Authentication code" name="code">
                    <x-ui.input type="text" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" autofocus x-bind:disabled="recovery" />
                </x-ui.field>
            </div>

            <div x-show="recovery" x-cloak>
                <x-ui.field label="Recovery code" name="recovery_code">
                    <x-ui.input type="text" name="recovery_code" autocomplete="one-time-code" x-bind:disabled="! recovery" />
                </x-ui.field>
            </div>

            <x-ui.button type="submit" variant="primary" block>Continue</x-ui.button>

            <button type="button" class="lq-link text-sm text-center" x-on:click="recovery = ! recovery">
                <span x-show="! recovery">Use a recovery code instead</span>
                <span x-show="recovery" x-cloak>Use the authenticator app instead</span>
            </button>
        </form>
    </div>
</x-layouts.guest>
