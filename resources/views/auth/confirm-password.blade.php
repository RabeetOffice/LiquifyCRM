<x-layouts.guest title="Confirm password">
    <div class="lq-glass">
        <div class="lq-card-header-stack">
            <h1 class="lq-title">Confirm your password</h1>
            <p class="lq-muted">This is a sensitive area. Please confirm your password before continuing.</p>
        </div>

        <form method="POST" action="{{ route('password.confirm') }}" class="lq-form">
            @csrf

            <x-ui.field label="Password" name="password" required>
                <x-ui.input type="password" name="password" required autofocus autocomplete="current-password" />
            </x-ui.field>

            <x-ui.button type="submit" variant="primary" block>Confirm</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
