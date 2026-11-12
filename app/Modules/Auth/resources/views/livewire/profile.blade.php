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
                        {{-- A brand opens only while the access is on and the brand is active; otherwise the chip says why. --}}
                        @if ($membership->isActive() && $membership->brand->is_active)
                            <a href="{{ $membership->brand->url() }}" class="lq-badge lq-badge-{{ $membership->role->getColor() }}" wire:navigate>
                                <x-heroicon-m-building-storefront />
                                {{ $membership->role->getLabel() }} · {{ $membership->brand->name }}
                            </a>
                        @else
                            <x-ui.badge color="gray" icon="heroicon-m-pause-circle">{{ $membership->role->getLabel() }} · {{ $membership->brand->name }} · {{ $membership->brand->is_active ? $membership->status->getLabel() : 'Brand switched off' }}</x-ui.badge>
                        @endif
                    @endforeach
                    @php $severalBrands = $memberships->count() > 1; @endphp
                    @foreach ($departments as $department)
                        {{-- In several brands the same team name can come twice: the brand tells them apart. --}}
                        <x-ui.badge color="gray" icon="heroicon-m-rectangle-group">{{ $department->name }}{{ $severalBrands ? ' · '.($memberships->firstWhere('brand_id', $department->brand_id)?->brand?->name ?? '') : '' }}</x-ui.badge>
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
                    <x-ui.field label="Time zone" name="timezone" help="Dates and follow-up times are shown, and typed, in this time zone. Automatic follows the computer you are using." class="lq-col-full">
                        <x-ui.select wire:model="timezone" name="timezone">
                            <option value="">Automatic: this computer's time zone{{ $computerZone ? ' ('.str_replace('_', ' ', $computerZone).')' : '' }}</option>
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

        @if ($chatCard)
            {{-- How visitors of the website chat see this person: the chat box's header shows whoever looks after the chat. --}}
            <x-ui.card title="In the website chat" description="Visitors see this at the top of the chat box when you look after their chat. Empty: your first name, your designation and your profile photo.">
                <div class="lq-chatcard">
                    <div class="lq-chatcard-preview" aria-hidden="true">
                        <span class="lq-chatcard-av">
                            @if ($chatCard['photo'])
                                <img src="{{ $chatCard['photo'] }}" alt="">
                            @else
                                {{ mb_strtoupper(mb_substr($chatCard['name'], 0, 1)) }}
                            @endif
                            <i></i>
                        </span>
                        <span class="lq-chatcard-words"><strong>{{ $chatCard['name'] }}</strong><small>{{ $chatCard['title'] ?? 'We usually reply in a few minutes' }}</small></span>
                    </div>

                    <div class="lq-chatcard-photo">
                        <label class="lq-btn lq-btn-secondary lq-btn-sm">
                            <x-heroicon-o-camera /> <span wire:loading.remove wire:target="chatPhoto">{{ $user->chat_photo ? 'Change chat photo' : 'Add a chat photo' }}</span><span wire:loading wire:target="chatPhoto">Saving…</span>
                            <input type="file" wire:model="chatPhoto" accept="image/*" class="sr-only" aria-label="Chat photo">
                        </label>
                        @if ($user->chat_photo)
                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-sm" wire:click="removeChatPhoto">Use my profile photo</button>
                        @endif
                    </div>
                    @error('chatPhoto') <p class="lq-error">{{ $message }}</p> @enderror

                    <form wire:submit="saveChatProfile" class="lq-form">
                        <div class="lq-form-grid">
                            <x-ui.field label="Name in the chat" name="chatName">
                                <x-ui.input wire:model="chatName" name="chatName" maxlength="60" :placeholder="explode(' ', trim($user->name))[0]" />
                            </x-ui.field>
                            <x-ui.field label="Title in the chat" name="chatTitle">
                                <x-ui.input wire:model="chatTitle" name="chatTitle" maxlength="80" :placeholder="$user->designation ?: 'Publishing consultant'" />
                            </x-ui.field>
                        </div>
                        <div class="flex justify-end">
                            <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="saveChatProfile">Save</x-ui.button>
                        </div>
                    </form>
                </div>
            </x-ui.card>
        @endif

        <div class="flex flex-col gap-6">
            @if ($targets !== [])
                <x-ui.card title="Your target this month" description="{{ now()->format('F Y') }}. Sales you closed count towards it.">
                    <div class="flex flex-col gap-5">
                        @foreach ($targets as $row)
                            <div wire:key="target-{{ $row['brand']->getKey() }}">
                                <div class="mb-1.5 flex items-center justify-between gap-3">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <x-ui.avatar :name="$row['brand']->name" :src="$row['brand']->avatarUrl()" :color="$row['brand']->primaryColor()" :tile="$row['brand']->tileColor()" size="sm" brand />
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
