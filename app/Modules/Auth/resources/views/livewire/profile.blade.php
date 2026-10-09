@php
    $initials = collect(explode(' ', trim($user->name)))->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
@endphp
<div class="flex flex-col gap-6">
    {{-- Who you are: photo, name, designation, role, where you work. --}}
    <section class="lq-profile-hero">
        <div class="lq-profile-cover" aria-hidden="true"></div>

        <div class="lq-profile-main">
            <label class="lq-profile-avatar" title="Change photo">
                @if ($user->avatarUrl())
                    <img src="{{ $user->avatarUrl() }}" alt="">
                @else
                    <span aria-hidden="true">{{ $initials !== '' ? $initials : '?' }}</span>
                @endif
                <span class="lq-profile-avatar-edit" wire:loading.class="opacity-100" wire:target="avatar">
                    <x-heroicon-o-camera wire:loading.remove wire:target="avatar" />
                    <x-heroicon-o-arrow-path class="animate-spin" wire:loading wire:target="avatar" />
                </span>
                <input type="file" wire:model="avatar" accept="image/*" class="sr-only" aria-label="Change photo">
            </label>

            <div class="lq-profile-id">
                <h1 class="lq-profile-name">{{ $user->name }}</h1>
                <p class="lq-profile-role">{{ $user->designation ?: $user->roleLabelIn(null) }}</p>

                <div class="lq-profile-chips">
                    @if ($user->role)
                        <x-ui.badge :color="$user->role->getColor()" :icon="$user->isSuperAdmin() ? 'heroicon-m-shield-check' : 'heroicon-m-key'">{{ $user->role->getLabel() }} · every brand</x-ui.badge>
                    @endif
                    @foreach ($memberships as $membership)
                        <a href="{{ $membership->brand->url() }}" class="lq-badge lq-badge-{{ $membership->role->getColor() }}" wire:navigate>
                            <x-heroicon-m-building-storefront />
                            {{ $membership->role->getLabel() }} · {{ $membership->brand->name }}
                        </a>
                    @endforeach
                    @foreach ($departments as $department)
                        <x-ui.badge color="gray" icon="heroicon-m-rectangle-group">{{ $department->name }}</x-ui.badge>
                    @endforeach
                    @if ($user->hasTwoFactorEnabled())
                        <x-ui.badge color="success" icon="heroicon-m-lock-closed">2FA on</x-ui.badge>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 pb-1">
                @if ($user->avatar_path)
                    <x-ui.button size="sm" variant="ghost" wire:click="removeAvatar">Remove photo</x-ui.button>
                @endif
                <x-ui.button size="sm" variant="secondary" icon="heroicon-o-lock-closed" :href="route('profile.security')">Password and 2FA</x-ui.button>
            </div>
        </div>

        @error('avatar') <p class="lq-error px-7 pb-4">{{ $message }}</p> @enderror

        <dl class="lq-profile-facts">
            @foreach ($facts as $fact)
                <div class="lq-profile-fact">
                    <dt class="lq-profile-fact-label">{{ $fact['label'] }}</dt>
                    <dd class="lq-profile-fact-value m-0" x-count>{{ $fact['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="lq-grid-main">
        <x-ui.card title="Your details" description="How colleagues see you and how LiquifyCRM reaches you.">
            <form wire:submit="save" class="lq-form">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Full name" name="name" required>
                        <x-ui.input wire:model="name" name="name" required autocomplete="name" />
                    </x-ui.field>
                    <x-ui.field label="Designation" name="designation" help="Your job title.">
                        <x-ui.input wire:model="designation" name="designation" placeholder="Senior Sales Executive" autocomplete="organization-title" />
                    </x-ui.field>
                    <x-ui.field label="Email address" name="email" required help="You sign in with this.">
                        <x-ui.input type="email" wire:model="email" name="email" required autocomplete="email" />
                    </x-ui.field>
                    <x-ui.field label="Phone" name="phone">
                        <x-ui.input type="tel" wire:model="phone" name="phone" autocomplete="tel" />
                    </x-ui.field>
                    <x-ui.field label="Timezone" name="timezone" help="Dates and follow-up times are shown in this timezone." class="lq-col-full">
                        <x-ui.select wire:model="timezone" name="timezone">
                            @foreach ($timezones as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                <div class="flex justify-end">
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save changes</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="flex flex-col gap-6">
            @if ($targets !== [])
                <x-ui.card title="Your target this month" description="{{ now()->format('F Y') }}. Sales you closed count towards it.">
                    <div class="flex flex-col gap-5">
                        @foreach ($targets as $row)
                            <div wire:key="target-{{ $row['brand']->getKey() }}">
                                <div class="mb-1.5 flex items-center justify-between gap-3">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <x-ui.avatar :name="$row['brand']->name" :src="$row['brand']->avatarUrl()" :color="$row['brand']->primaryColor()" size="sm" brand />
                                        <span class="lq-cell-title truncate">{{ $row['brand']->name }}</span>
                                    </span>
                                    <a class="lq-link text-sm" href="{{ route('brand.sales', ['brand' => $row['brand']->slug]) }}" wire:navigate>See sales</a>
                                </div>
                                <x-sales::progress :progress="$row['progress']" />
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="Account">
                <dl class="lq-dl">
                    <div><dt>Role</dt><dd>{{ $user->roleLabelIn(null) }}</dd></div>
                    <div><dt>Last sign-in</dt><dd>{{ $user->last_login_at?->diffForHumans() ?? 'First time' }}</dd></div>
                    <div><dt>Two-factor</dt><dd>{{ $user->hasTwoFactorEnabled() ? 'On' : 'Not set up' }}</dd></div>
                </dl>
            </x-ui.card>
        </div>
    </div>
</div>
