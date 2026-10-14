<x-layouts.guest title="Forgot password">
    <div class="lq-glass">
        <div class="lq-card-header-stack">
            <h1 class="lq-title">Forgot your password?</h1>
            <p class="lq-muted">Enter your email and we will send a link to choose a new one.</p>
        </div>

        @if (session('status'))
            <div class="lq-alert lq-alert-success" role="status">
                <x-heroicon-o-check-circle />
                <span>{{ session('status') }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="lq-form">
            @csrf

            <x-ui.field label="Email address" name="email">
                <div class="lq-input-icon">
                    <x-heroicon-o-envelope />
                    <x-ui.input type="email" name="email" :value="old('email')" required autofocus autocomplete="username" inputmode="email" placeholder="you@company.com" />
                </div>
            </x-ui.field>

            <x-ui.button type="submit" variant="primary" block>Email me a reset link</x-ui.button>
        </form>

        <p class="lq-hint text-center">
            Have an authenticator app? <a href="{{ route('password.authenticator') }}" class="lq-link">Reset with its code instead</a>
        </p>

        <p class="lq-hint text-center"><a href="{{ route('login') }}" class="lq-link">Back to sign in</a></p>
    </div>
</x-layouts.guest>
