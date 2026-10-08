{{-- LiquifyCRM shell: full-height sidebar with the logo, brand switcher, flat groups and a user card. --}}
<div>
    @php
        use Filament\Support\Enums\IconSize;

        $navigation = filament()->getNavigation();
        $hasTenantMenu = filament()->hasTenancy() && filament()->hasTenantMenu();
        $user = filament()->auth()->user();
        $tenant = filament()->getTenant();
        $roleLabel = match (true) {
            ! $user instanceof \App\Modules\Auth\Models\User => '',
            $user->isSuperAdmin() => 'Super Admin',
            $user->isAdmin() => 'Administrator',
            $tenant instanceof \App\Modules\Brands\Models\Brand => $user->brandRole($tenant)?->getLabel() ?? 'Member',
            default => 'Member',
        };
        $profileUrl = filament()->hasProfile() ? filament()->getProfileUrl() : null;
    @endphp

    <div
        x-data="{}"
        x-cloak
        x-bind:class="{ 'fi-sidebar-open': $store.sidebar.isOpen }"
        id="fi-main-sidebar"
        class="fi-sidebar fi-main-sidebar lq-sidebar"
    >
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_START) }}

        <div class="lq-side-header">
            <a {{ \Filament\Support\generate_href_html(filament()->getHomeUrl() ?? filament()->getUrl()) }} class="lq-side-logo" aria-label="LiquifyCRM">
                <img src="{{ asset('images/liquify-mark.svg') }}" alt="" class="lq-side-logo-mark">
                <span x-show="$store.sidebar.isOpen" class="lq-logo-text">Liquify<span class="lq-logo-accent">CRM</span></span>
            </a>

            <button
                type="button"
                class="lq-side-close"
                aria-controls="fi-main-sidebar"
                x-bind:aria-expanded="$store.sidebar.isOpen"
                x-on:click="$store.sidebar.close()"
                title="{{ __('filament-panels::layout.actions.sidebar.collapse.label') }}"
            >
                <x-filament::icon icon="heroicon-o-x-mark" class="lq-side-close-icon" />
            </button>
        </div>

        @if ($hasTenantMenu)
            <div class="lq-side-brand">
                <x-filament-panels::tenant-menu />
            </div>
        @endif

        <nav aria-label="{{ __('filament-panels::layout.navigation.label') }}" class="lq-side-nav">
            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_NAV_START) }}

            @foreach ($navigation as $group)
                @php
                    $groupLabel = $group->getLabel() ?: 'Main menu';
                @endphp

                <div class="lq-side-group">
                    <div class="lq-side-group-label" x-show="$store.sidebar.isOpen">{{ $groupLabel }}</div>

                    <ul class="lq-side-items">
                        @foreach ($group->getItems() as $item)
                            @php
                                $isChildItemsActive = $item->isChildItemsActive();
                                $isActive = (! $isChildItemsActive) && $item->isActive();
                                $icon = ($isActive && $item->getActiveIcon()) ? $item->getActiveIcon() : $item->getIcon();
                                $badge = $item->getBadge();
                                $label = $item->getLabel();
                                $childItems = $item->getChildItems();
                            @endphp

                            <li @class(['lq-side-item', 'lq-active' => $isActive, 'lq-has-active-child' => $isChildItemsActive])>
                                <a
                                    {{ \Filament\Support\generate_href_html($item->getUrl(), $item->shouldOpenUrlInNewTab()) }}
                                    @if ($isActive) aria-current="page" @endif
                                    x-on:click="window.matchMedia(`(max-width: 1024px)`).matches && $store.sidebar.close()"
                                    x-data="{ tooltip: false }"
                                    x-effect="
                                        tooltip = $store.sidebar.isOpen
                                            ? false
                                            : { content: @js($label), placement: document.dir === 'rtl' ? 'left' : 'right', theme: $store.theme }
                                    "
                                    x-tooltip.html="tooltip"
                                    class="lq-side-link"
                                >
                                    @if ($icon)
                                        {{ \Filament\Support\generate_icon_html($icon, size: IconSize::Large) }}
                                    @endif

                                    <span class="lq-side-label" x-show="$store.sidebar.isOpen">{{ $label }}</span>

                                    @if (filled($badge))
                                        <span class="lq-side-badge" x-show="$store.sidebar.isOpen">
                                            <x-filament::badge :color="$item->getBadgeColor($badge)" :tooltip="$item->getBadgeTooltip($badge)" size="sm">
                                                {{ $badge }}
                                            </x-filament::badge>
                                        </span>
                                    @endif
                                </a>

                                @if ($childItems && ($isActive || $isChildItemsActive))
                                    <ul class="lq-side-subitems" x-show="$store.sidebar.isOpen">
                                        @foreach ($childItems as $childItem)
                                            <li @class(['lq-side-subitem', 'lq-active' => $childItem->isActive()])>
                                                <a {{ \Filament\Support\generate_href_html($childItem->getUrl(), $childItem->shouldOpenUrlInNewTab()) }} class="lq-side-sublink">
                                                    {{ $childItem->getLabel() }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_NAV_END) }}
        </nav>

        @if ($user)
            <div class="lq-side-footer">
                <a @if ($profileUrl) {{ \Filament\Support\generate_href_html($profileUrl) }} @endif class="lq-side-user" title="{{ $user->getFilamentName() }}">
                    <x-filament-panels::avatar.user :user="$user" class="lq-side-user-avatar" />
                    <span class="lq-side-user-text" x-show="$store.sidebar.isOpen">
                        <span class="lq-side-user-name">{{ $user->getFilamentName() }}</span>
                        <span class="lq-side-user-role">{{ $roleLabel }}</span>
                    </span>
                </a>
            </div>
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_FOOTER) }}
    </div>

    <x-filament-actions::modals />
</div>
