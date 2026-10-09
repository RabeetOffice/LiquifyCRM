{{-- Full-height sidebar: logo, where-am-I switcher, flat groups, account menu. --}}
@php
    $brands = $user?->accessibleBrands() ?? collect();
    $isAdmin = (bool) $user?->isGlobalAdmin();
    $showSwitcher = $brand !== null || ($isAdmin && $brands->isNotEmpty());
@endphp

<aside class="lq-sidebar" id="main-sidebar" aria-label="Main navigation">
    <div class="lq-side-header">
        <a href="{{ $brand ? $brand->url() : ($isAdmin ? route('admin.dashboard') : url('/')) }}" class="lq-side-logo" aria-label="LiquifyCRM" wire:navigate>
            <img src="{{ asset('images/liquify-mark.svg') }}" alt="" class="lq-side-logo-mark">
            <span class="lq-logo-text">Liquify<span class="lq-logo-accent">CRM</span></span>
        </a>

        <button type="button" class="lq-side-close" x-on:click="$store.sidebar.closeMobile()" title="Close menu">
            <x-heroicon-o-x-mark class="h-5 w-5" />
        </button>
    </div>

    {{-- One place to move between "all brands" (the admin area) and each brand. --}}
    @if ($showSwitcher)
        <div class="lq-side-brand" x-data="dropdown" x-on:click.outside="close()" x-on:keydown.escape="close()">
            <button type="button" class="lq-side-brand-btn" x-on:click="toggle()" aria-haspopup="menu" x-bind:aria-expanded="open" title="{{ $brand?->name ?? 'All brands' }}">
                @if ($brand !== null)
                    <x-ui.avatar :name="$brand->name" :src="$brand->avatarUrl()" :color="$brand->primaryColor()" size="sm" brand />
                @else
                    <span class="lq-avatar lq-avatar-sm lq-avatar-brand lq-avatar-all"><x-heroicon-m-squares-2x2 /></span>
                @endif
                <span class="lq-side-brand-text">
                    <span class="lq-side-brand-label">{{ $brand !== null ? 'Brand' : 'Admin area' }}</span>
                    <span class="lq-side-brand-name">{{ $brand?->name ?? 'All brands' }}</span>
                </span>
                <x-heroicon-o-chevron-up-down class="lq-side-brand-chevron" />
            </button>

            <div class="lq-menu lq-menu-pop" style="inset-inline: 0.875rem; top: calc(100% - 0.375rem);" x-show="open" x-cloak role="menu">
                @if ($isAdmin)
                    <a href="{{ route('admin.dashboard') }}" class="lq-menu-item" role="menuitem" wire:navigate>
                        <span class="lq-avatar lq-avatar-sm lq-avatar-brand lq-avatar-all"><x-heroicon-m-squares-2x2 /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">All brands</span>
                            <span class="block text-xs lq-muted">Admin area</span>
                        </span>
                        @if ($brand === null)
                            <x-heroicon-m-check class="h-4 w-4" />
                        @endif
                    </a>
                    @if ($brands->isNotEmpty())
                        <div class="lq-menu-divider"></div>
                    @endif
                @endif

                @foreach ($brands as $item)
                    <a href="{{ $item->url() }}" class="lq-menu-item" role="menuitem" wire:navigate>
                        <x-ui.avatar :name="$item->name" :src="$item->avatarUrl()" :color="$item->primaryColor()" size="sm" brand />
                        <span class="flex-1 truncate">{{ $item->name }}</span>
                        @if ($brand !== null && $item->is($brand))
                            <x-heroicon-m-check class="h-4 w-4" />
                        @endif
                    </a>
                @endforeach

                @if ($isAdmin)
                    <div class="lq-menu-divider"></div>
                    <a href="{{ route('admin.brands.create') }}" class="lq-menu-item" role="menuitem" wire:navigate>
                        <x-heroicon-o-plus /> Add brand
                    </a>
                @endif
            </div>
        </div>
    @endif

    <nav class="lq-side-nav">
        @foreach ($menu as $group)
            <div class="lq-side-group">
                <div class="lq-side-group-label">{{ $group['label'] }}</div>
                <ul class="lq-side-items">
                    @foreach ($group['items'] as $item)
                        <li @class(['lq-side-item', 'lq-active' => $item['active']])>
                            <a href="{{ $item['url'] }}" class="lq-side-link" @if ($item['active']) aria-current="page" @endif title="{{ $item['label'] }}" x-on:click="$store.sidebar.closeMobile()" wire:navigate>
                                @svg($item['icon'], 'lq-side-icon')
                                <span class="lq-side-label">{{ $item['label'] }}</span>
                                @if ($item['badge'])
                                    <span class="lq-side-badge"><span class="lq-badge lq-badge-primary">{{ $item['badge'] }}</span></span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    {{-- The one account menu: profile, password, sign out. --}}
    @if ($user)
        <div class="lq-side-footer" x-data="dropdown" x-on:click.outside="close()" x-on:keydown.escape="close()">
            <div class="lq-menu lq-menu-pop lq-menu-up" x-show="open" x-cloak role="menu">
                <div class="lq-menu-header">
                    <div class="lq-ink text-sm font-semibold truncate">{{ $user->name }}</div>
                    <div class="truncate">{{ $user->email }}</div>
                </div>
                <div class="lq-menu-divider"></div>
                <a href="{{ route('profile') }}" class="lq-menu-item" role="menuitem" wire:navigate>
                    <x-heroicon-o-user-circle /> My profile
                </a>
                <a href="{{ route('profile.security') }}" class="lq-menu-item" role="menuitem" wire:navigate>
                    <x-heroicon-o-lock-closed /> Password and 2FA
                </a>
                <div class="lq-menu-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="lq-menu-item lq-menu-item-danger" role="menuitem">
                        <x-heroicon-o-arrow-right-start-on-rectangle /> Sign out
                    </button>
                </form>
            </div>

            <button type="button" class="lq-side-user" x-on:click="toggle()" aria-haspopup="menu" x-bind:aria-expanded="open" title="{{ $user->name }}">
                <x-ui.avatar :name="$user->name" :src="$user->avatarUrl()" />
                <span class="lq-side-user-text">
                    <span class="lq-side-user-name">{{ $user->name }}</span>
                    <span class="lq-side-user-role">{{ $user->designation ?: $user->roleLabelIn($brand) }}</span>
                </span>
                <x-heroicon-o-ellipsis-horizontal class="lq-side-brand-chevron" />
            </button>
        </div>
    @endif
</aside>
