<x-layouts.guest title="Sign in">
    <div class="lq-glass">
        <div class="lq-card-header-stack">
            <h1 class="lq-title">Sign in</h1>
            <p class="lq-muted">Welcome back. Use the email and password your Admin gave you.</p>
        </div>

        @if (session('status'))
            <div class="lq-alert lq-alert-warning" role="status">
                <x-heroicon-o-information-circle />
                <span>{{ session('status') }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="lq-form">
            @csrf

            <x-ui.field label="Email address" name="email" required>
                <x-ui.input type="email" name="email" :value="old('email')" required autofocus autocomplete="username" inputmode="email" />
            </x-ui.field>

            <x-ui.field name="password" required>
                <div class="flex items-center justify-between">
                    <label for="password" class="lq-label lq-label-required">Password</label>
                    <a href="{{ route('password.request') }}" class="lq-link text-sm">Forgot password?</a>
                </div>
                <x-ui.input type="password" name="password" required autocomplete="current-password" />
            </x-ui.field>

            <x-ui.checkbox name="remember" label="Remember me" :checked="old('remember')" />

            <x-ui.button type="submit" variant="primary" block>Sign in</x-ui.button>
        </form>
    </div>
</x-layouts.guest>
