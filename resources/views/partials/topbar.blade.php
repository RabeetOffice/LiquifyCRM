{{-- Top bar over the content: menu toggle, greeting, status, search, bell, theme, avatar. --}}
@php
    $timezone = $user?->timezone ?: config('app.timezone');
    $now = now($timezone);
    $hour = (int) $now->format('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $firstName = $user ? str($user->name)->before(' ')->toString() : '';
@endphp

<header class="lq-topbar">
    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-on:click="$store.sidebar.toggle()" title="Menu" aria-controls="main-sidebar">
        <x-heroicon-o-bars-3-bottom-left class="h-5 w-5" />
    </button>

    @if ($user)
        <div class="lq-top-greeting">
            <span class="lq-top-greeting-title">{{ $greeting }}{{ $firstName ? ', '.$firstName : '' }}</span>
            <span class="lq-top-greeting-date">{{ $now->translatedFormat('l, j F Y') }}</span>
        </div>
    @endif

    <div class="lq-top-end">
        @if ($brand === null && $user?->isSuperAdmin())
            @php
                $status = app(\App\Modules\System\Support\SystemStatus::class);
                $last = $status->lastCronRun();
                $healthy = $status->cronIsHealthy();
            @endphp
            <a href="{{ route('admin.system') }}" @class(['lq-status', 'lq-status-warn' => ! $healthy]) title="Scheduler status" wire:navigate>
                <span class="lq-status-dot" aria-hidden="true"></span>
                <span class="lq-status-label">{{ $healthy ? 'Running' : 'Scheduler quiet' }}</span>
                <span class="lq-status-detail">{{ $last ? 'Last run '.$last->diffForHumans() : 'No run recorded' }}</span>
            </a>
        @endif

        @livewire(\App\Modules\Core\Livewire\GlobalSearch::class)

        @livewire(\App\Modules\Core\Livewire\NotificationBell::class)

        <button
            type="button"
            class="lq-btn lq-btn-ghost lq-btn-icon"
            x-on:click="$store.theme.toggle()"
            x-bind:title="$store.theme.current === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
        >
            <x-heroicon-o-sun class="h-5 w-5" x-show="$store.theme.current === 'dark'" x-cloak />
            <x-heroicon-o-moon class="h-5 w-5" x-show="$store.theme.current !== 'dark'" />
        </button>

        @if ($user)
            <div class="relative" x-data="dropdown" x-on:click.outside="close()" x-on:keydown.escape="close()">
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-on:click="toggle()" aria-haspopup="menu" x-bind:aria-expanded="open" title="{{ $user->name }}">
                    <x-ui.avatar :name="$user->name" :src="$user->avatarUrl()" size="sm" />
                </button>

                <div class="lq-menu lq-menu-end" style="top: calc(100% + 0.5rem); min-width: 14rem;" x-show="open" x-transition.opacity x-cloak role="menu">
                    <div class="lq-menu-header">
                        <div class="lq-ink font-semibold text-sm">{{ $user->name }}</div>
                        <div class="truncate">{{ $user->email }}</div>
                    </div>
                    <div class="lq-menu-divider"></div>
                    <a href="{{ route('profile') }}" class="lq-menu-item" role="menuitem" wire:navigate>
                        <x-heroicon-o-user-circle /> Profile
                    </a>
                    <a href="{{ route('profile.security') }}" class="lq-menu-item" role="menuitem" wire:navigate>
                        <x-heroicon-o-lock-closed /> Password and 2FA
                    </a>
                    @if ($user->isGlobalAdmin())
                        @if ($brand !== null)
                            <a href="{{ route('admin.dashboard') }}" class="lq-menu-item" role="menuitem" wire:navigate>
                                <x-heroicon-o-shield-check /> Admin area
                            </a>
                        @elseif ($user->defaultBrand())
                            <a href="{{ $user->defaultBrand()->url() }}" class="lq-menu-item" role="menuitem" wire:navigate>
                                <x-heroicon-o-squares-2x2 /> Open a brand
                            </a>
                        @endif
                    @endif
                    <div class="lq-menu-divider"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="lq-menu-item lq-menu-item-danger" role="menuitem">
                            <x-heroicon-o-arrow-right-start-on-rectangle /> Sign out
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>
</header>
