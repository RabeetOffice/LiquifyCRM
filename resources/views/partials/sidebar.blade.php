{{-- Full-height sidebar: logo, brand switcher, flat groups, user card. --}}
@php
    $brands = $user?->accessibleBrands() ?? collect();
@endphp

<aside class="lq-sidebar" id="main-sidebar" aria-label="Main navigation">
    <div class="lq-side-header">
        <a href="{{ $brand ? $brand->url() : ($user?->isGlobalAdmin() ? route('admin.dashboard') : url('/')) }}" class="lq-side-logo" aria-label="LiquifyCRM" wire:navigate>
            <img src="{{ asset('images/liquify-mark.svg') }}" alt="" class="lq-side-logo-mark">
            <span class="lq-logo-text">Liquify<span class="lq-logo-accent">CRM</span></span>
        </a>

        <button type="button" class="lq-side-close" x-on:click="$store.sidebar.closeMobile()" title="Close menu">
            <x-heroicon-o-x-mark class="h-5 w-5" />
        </button>
    </div>

    @if ($brand !== null)
        <div class="lq-side-brand" x-data="dropdown" x-on:click.outside="close()">
            <button type="button" class="lq-side-brand-btn" x-on:click="toggle()" aria-haspopup="menu" x-bind:aria-expanded="open">
                <x-ui.avatar :name="$brand->name" :src="$brand->avatarUrl()" :color="$brand->primaryColor()" size="sm" brand />
                <span class="lq-side-brand-text">
                    <span class="lq-side-brand-label">Current brand</span>
                    <span class="lq-side-brand-name">{{ $brand->name }}</span>
                </span>
                <x-heroicon-o-chevron-up-down class="lq-side-brand-chevron" />
            </button>

            <div class="lq-menu" style="inset-inline: 0.875rem; top: 100%;" x-show="open" x-transition.opacity x-cloak role="menu">
                <div class="lq-menu-header">Switch brand</div>
                @foreach ($brands as $item)
                    <a href="{{ $item->url() }}" class="lq-menu-item" role="menuitem" wire:navigate>
                        <x-ui.avatar :name="$item->name" :src="$item->avatarUrl()" :color="$item->primaryColor()" size="sm" brand />
                        <span class="flex-1 truncate">{{ $item->name }}</span>
                        @if ($item->is($brand))
                            <x-heroicon-m-check class="h-4 w-4" />
                        @endif
                    </a>
                @endforeach
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

    @if ($user)
        <div class="lq-side-footer">
            <a href="{{ route('profile') }}" class="lq-side-user" title="{{ $user->name }}" wire:navigate>
                <x-ui.avatar :name="$user->name" :src="$user->avatarUrl()" />
                <span class="lq-side-user-text">
                    <span class="lq-side-user-name">{{ $user->name }}</span>
                    <span class="lq-side-user-role">{{ $user->roleLabelIn($brand) }}</span>
                </span>
            </a>
        </div>
    @endif
</aside>
