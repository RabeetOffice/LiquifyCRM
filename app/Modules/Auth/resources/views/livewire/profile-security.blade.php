<div class="flex flex-col gap-6">
    <x-ui.page-header title="Password and 2FA" subtitle="Keep your account safe." />

    @if (session('status'))
        <div class="lq-alert lq-alert-warning" role="status">
            <x-heroicon-o-information-circle />
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="lq-grid-main">
        <x-ui.card title="Authenticator app" :description="$required ? 'Required for Super Admins and Admins.' : 'Optional, but recommended.'">
            @if ($enabled)
                <div class="flex flex-col gap-4">
                    <div class="lq-alert lq-alert-success">
                        <x-heroicon-o-shield-check />
                        <span>Two-factor authentication is on. You will be asked for a code from your app when you sign in.</span>
                    </div>

                    @if ($recoveryCodes !== [])
                        <div>
                            <p class="lq-label">Recovery codes</p>
                            <p class="lq-help mb-2">Each code works once. Keep them somewhere safe; they get you in if you lose your phone.</p>
                            <div class="grid grid-cols-2 gap-2 rounded-xl p-4 lq-tabular text-sm" style="background: var(--lq-raised)">
                                @foreach ($recoveryCodes as $recoveryCode)
                                    <code>{{ $recoveryCode }}</code>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        @if ($recoveryCodes === [])
                            <x-ui.button size="sm" wire:click="$set('showRecoveryCodes', true)">Show recovery codes</x-ui.button>
                        @endif
                        <x-ui.button size="sm" wire:click="regenerateRecoveryCodes">New recovery codes</x-ui.button>
                        @unless ($required)
                            <x-ui.button size="sm" variant="danger" wire:click="disableTwoFactor">Turn off</x-ui.button>
                        @endunless
                    </div>
                </div>
            @elseif ($pending)
                <div class="flex flex-col gap-4">
                    <p class="text-sm">Scan this QR code with Google Authenticator, Microsoft Authenticator or 1Password, then type the 6-digit code it shows.</p>

                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                        <div class="rounded-xl bg-white p-3" style="box-shadow: 0 0 0 1px var(--lq-hairline)">{!! $qrCode !!}</div>
                        <div class="flex-1">
                            <p class="lq-help">Cannot scan? Enter this key by hand:</p>
                            <code class="lq-code break-all">{{ $secret }}</code>
                        </div>
                    </div>

                    <form wire:submit="confirmTwoFactor" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <x-ui.field label="6-digit code" name="code" class="flex-1">
                            <x-ui.input wire:model="code" name="code" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" autofocus />
                        </x-ui.field>
                        <div class="flex gap-2">
                            <x-ui.button type="submit" variant="primary">Confirm and turn on</x-ui.button>
                            <x-ui.button variant="ghost" wire:click="cancelTwoFactorSetup">Cancel</x-ui.button>
                        </div>
                    </form>
                </div>
            @else
                <div class="flex flex-col items-start gap-4">
                    <p class="text-sm">Add a second step at sign-in: a code from an authenticator app on your phone.</p>
                    <x-ui.button variant="primary" wire:click="enableTwoFactor" icon="heroicon-o-device-phone-mobile">Set up authenticator app</x-ui.button>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Change password" description="At least 10 characters with letters and numbers.">
            <form wire:submit="updatePassword" class="lq-form">
                <x-ui.field label="Current password" name="current_password" required>
                    <x-ui.input type="password" wire:model="current_password" name="current_password" autocomplete="current-password" />
                </x-ui.field>
                <x-ui.field label="New password" name="password" required>
                    <x-ui.input type="password" wire:model="password" name="password" autocomplete="new-password" />
                </x-ui.field>
                <x-ui.field label="Repeat new password" name="password_confirmation" required>
                    <x-ui.input type="password" wire:model="password_confirmation" name="password_confirmation" autocomplete="new-password" />
                </x-ui.field>
                <div class="flex justify-end">
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">Change password</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>

    <x-ui.confirm :confirm="$confirm" />
</div>
