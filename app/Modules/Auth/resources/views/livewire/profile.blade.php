<div class="flex flex-col gap-6">
    <x-ui.page-header title="Profile" subtitle="Your name, contact details and photo." />

    <div class="lq-grid-main">
        <x-ui.card title="Details">
            <form wire:submit="save" class="lq-form">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Name" name="name" required>
                        <x-ui.input wire:model="name" name="name" required autocomplete="name" />
                    </x-ui.field>
                    <x-ui.field label="Email address" name="email" required>
                        <x-ui.input type="email" wire:model="email" name="email" required autocomplete="email" />
                    </x-ui.field>
                    <x-ui.field label="Phone" name="phone">
                        <x-ui.input type="tel" wire:model="phone" name="phone" autocomplete="tel" />
                    </x-ui.field>
                    <x-ui.field label="Timezone" name="timezone" help="Dates and follow-up times are shown in this timezone.">
                        <x-ui.select wire:model="timezone" name="timezone">
                            @foreach ($timezones as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                <div class="flex justify-end">
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">Save changes</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card title="Photo" description="Shown next to your name in lists and the sidebar.">
            <div class="flex flex-col items-start gap-4">
                <x-ui.avatar :name="$user->name" :src="$avatar?->temporaryUrl() ?? $user->avatarUrl()" size="lg" />

                <x-ui.field name="avatar">
                    <input type="file" wire:model="avatar" accept="image/*" class="lq-input" style="padding: 0.375rem 0.625rem">
                    <span class="lq-help" wire:loading wire:target="avatar">Uploading…</span>
                </x-ui.field>

                <div class="flex gap-2">
                    <x-ui.button size="sm" variant="primary" wire:click="save" wire:loading.attr="disabled">Save photo</x-ui.button>
                    @if ($user->avatar_path)
                        <x-ui.button size="sm" variant="ghost" wire:click="removeAvatar">Remove</x-ui.button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
