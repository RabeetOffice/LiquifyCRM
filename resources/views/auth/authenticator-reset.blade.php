<x-layouts.guest title="Reset with authenticator">
    <div class="lq-glass">
        <div class="lq-card-header-stack">
            <h1 class="lq-title">Reset with your authenticator app</h1>
            <p class="lq-muted">Type the 6-digit code from your authenticator app and choose a new password. No email is needed.</p>
        </div>

        <form method="POST" action="{{ route('password.authenticator.update') }}" class="lq-form">
            @csrf

            <x-ui.field label="Email address" name="email" required>
                <x-ui.input type="email" name="email" :value="old('email')" required autofocus autocomplete="username" inputmode="email" />
            </x-ui.field>

            <x-ui.field label="Authentication code" name="code" required>
                <x-ui.input type="text" name="code" required inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" />
            </x-ui.field>

            <x-ui.field label="New password" name="password" required help="At least 10 characters with letters and numbers.">
                <x-ui.input type="password" name="password" required autocomplete="new-password" />
            </x-ui.field>

            <x-ui.field label="Repeat password" name="password_confirmation" required>
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>

            <x-ui.button type="submit" variant="primary" block>Save new password</x-ui.button>
        </form>

        <p class="lq-hint text-center">
            No authenticator app on your account? <a href="{{ route('password.request') }}" class="lq-link">Get a link by email</a>
            or <a href="{{ route('login') }}" class="lq-link">go back to sign in</a>.
        </p>
    </div>
</x-layouts.guest>
