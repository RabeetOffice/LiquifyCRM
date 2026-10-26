<x-layouts.guest title="Sign in">
    <div class="lq-card-header-stack">
        <h1 class="lq-title">Welcome back</h1>
        <p class="lq-muted">Sign in with the email and password your Admin gave you.</p>
    </div>

    @if (session('status'))
        <div @class(['lq-alert', session('status_type') === 'success' ? 'lq-alert-success' : 'lq-alert-warning']) role="status">
            <x-heroicon-o-information-circle />
            <span>{{ session('status') }}</span>
        </div>
    @endif

    {{-- Signed out by sign-in security (left the office network, a phone not allowed): the reason stays until they sign in. --}}
    @if (filled($refused ?? null) && ! $errors->any())
        <div class="lq-alert lq-alert-warning" role="alert">
            <x-heroicon-o-shield-exclamation />
            <span>{{ $refused }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="lq-form" x-data="{ busy: false, shown: false, caps: false }" x-on:submit="busy = true">
        @csrf
        {{-- What this page sees of the screen, so a phone asking for the desktop site is still known as a phone (Device::of). --}}
        <input type="hidden" name="device" x-init="$el.value = JSON.stringify({ touch: navigator.maxTouchPoints || 0, w: screen.width, h: screen.height, mobile: navigator.userAgentData ? navigator.userAgentData.mobile : null })">

        <x-ui.field label="Email address" name="email">
            <div class="lq-input-icon">
                <x-heroicon-o-envelope />
                <x-ui.input type="email" name="email" :value="old('email')" required autofocus autocomplete="username" inputmode="email" placeholder="you@company.com" />
            </div>
        </x-ui.field>

        <x-ui.field name="password">
            <div class="lq-label-row justify-between">
                <label for="password" class="lq-label">Password</label>
                <a href="{{ route('password.request') }}" class="lq-link text-sm">Forgot password?</a>
            </div>
            <div class="lq-input-icon lq-input-icon-reveal">
                <x-heroicon-o-lock-closed />
                <x-ui.input
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    x-bind:type="shown ? 'text' : 'password'"
                    x-on:keydown="caps = $event.getModifierState?.('CapsLock') ?? false"
                    x-on:blur="caps = false"
                />
                <button type="button" class="lq-input-reveal" x-on:click="shown = ! shown" x-bind:aria-pressed="shown ? 'true' : 'false'" x-bind:title="shown ? 'Hide password' : 'Show password'" aria-label="Show password">
                    <x-heroicon-o-eye x-show="! shown" />
                    <x-heroicon-o-eye-slash x-show="shown" x-cloak />
                </button>
            </div>
            <p class="lq-help lq-auth-caps" x-show="caps" x-cloak><x-heroicon-m-arrow-up /> Caps Lock is on</p>
        </x-ui.field>

        <x-ui.checkbox name="remember" label="Keep me signed in on this computer" :checked="old('remember')" />

        <button type="submit" class="lq-btn lq-btn-primary lq-btn-block lq-auth-submit" x-bind:disabled="busy">
            <span x-show="! busy">Sign in</span>
            <x-heroicon-m-arrow-right class="lq-auth-submit-arrow" x-show="! busy" />
            <span class="lq-auth-spinner" x-show="busy" x-cloak></span>
            <span x-show="busy" x-cloak>Signing in…</span>
        </button>
    </form>

    <p class="lq-auth-note">New to LiquifyCRM? Your Admin sends you an invitation by email.</p>
</x-layouts.guest>
