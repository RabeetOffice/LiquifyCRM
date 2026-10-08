<x-layouts.guest title="Choose a new password">
    <div class="lq-glass">
        <div class="lq-card-header-stack">
            <h1 class="lq-title">Choose a new password</h1>
            <p class="lq-muted">At least 10 characters with letters and numbers.</p>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="lq-form">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <x-ui.field label="Email address" name="email" required>
                <x-ui.input type="email" name="email" :value="old('email', $request->email)" required autocomplete="username" inputmode="email" />
            </x-ui.field>

            <x-ui.field label="New password" name="password" required>
                <x-ui.input type="password" name="password" required autofocus autocomplete="new-password" />
            </x-ui.field>

            <x-ui.field label="Repeat password" name="password_confirmation" required>
                <x-ui.input type="password" name="password_confirmation" required autocomplete="new-password" />
            </x-ui.field>

            <x-ui.button type="submit" variant="primary" block>Save password and sign in</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
