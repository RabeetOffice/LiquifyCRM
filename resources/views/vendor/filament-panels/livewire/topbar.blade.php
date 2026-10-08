{{-- LiquifyCRM shell: top bar over the content with greeting, search, bell, theme toggle and avatar. --}}
@php
    use Filament\Enums\DatabaseNotificationsPosition;
    use Filament\Enums\GlobalSearchPosition;
    use Filament\Enums\UserMenuPosition;
    use Filament\Livewire\GlobalSearch;
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $hasNavigation = filament()->hasNavigation();
    $hasTenancy = filament()->hasTenancy();
    $user = filament()->auth()->user();
    $timezone = ($user?->getAttribute('timezone')) ?: config('app.timezone');
    $now = now($timezone);
    $hour = (int) $now->format('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $firstName = $user ? str($user->getFilamentName())->before(' ')->toString() : '';
@endphp

<div
    class="fi-topbar-ctn lq-topbar-ctn"
    x-data="{}"
    x-bind:class="{ 'lq-sidebar-open': $store.sidebar.isOpen }"
>
    <nav aria-label="{{ __('filament-panels::layout.topbar.label') }}" class="fi-topbar lq-topbar">
        {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_START) }}

        @if ($hasNavigation)
            <button
                type="button"
                class="lq-top-icon-btn lq-top-menu-btn"
                aria-controls="fi-main-sidebar"
                x-bind:aria-expanded="$store.sidebar.isOpen"
                x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
                title="{{ __('filament-panels::layout.actions.sidebar.expand.label') }}"
            >
                <x-filament::icon icon="heroicon-o-bars-3-bottom-left" class="lq-top-icon" />
            </button>
        @endif

        @if ($user)
            <div class="lq-top-greeting">
                <span class="lq-top-greeting-title">{{ $greeting }}{{ $firstName ? ', '.$firstName : '' }}</span>
                <span class="lq-top-greeting-date">{{ $now->translatedFormat('l, j F Y') }}</span>
            </div>
        @endif

        <div
            @if ($hasTenancy)
                x-persist="topbar.end.panel-{{ filament()->getId() }}.tenant-{{ filament()->getTenant()?->getKey() }}"
            @else
                x-persist="topbar.end.panel-{{ filament()->getId() }}"
            @endif
            class="fi-topbar-end lq-top-end"
        >
            {{ FilamentView::renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE) }}

            @if (filament()->isGlobalSearchEnabled() && filament()->getGlobalSearchPosition() === GlobalSearchPosition::Topbar)
                @livewire(GlobalSearch::class)
            @endif

            {{ FilamentView::renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER) }}

            @if (filament()->auth()->check())
                @if (filament()->hasDatabaseNotifications() && filament()->getDatabaseNotificationsPosition() === DatabaseNotificationsPosition::Topbar)
                    @livewire(filament()->getDatabaseNotificationsLivewireComponent(), [
                        'lazy' => filament()->hasLazyLoadedDatabaseNotifications(),
                    ])
                @endif

                @if (filament()->hasDarkMode() && ! filament()->hasDarkModeForced())
                    <button
                        type="button"
                        class="lq-top-icon-btn"
                        x-data="{}"
                        x-on:click="window.dispatchEvent(new CustomEvent('theme-changed', { detail: $store.theme === 'dark' ? 'light' : 'dark' }))"
                        x-bind:title="$store.theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
                    >
                        <x-filament::icon icon="heroicon-o-sun" class="lq-top-icon" x-show="$store.theme === 'dark'" x-cloak />
                        <x-filament::icon icon="heroicon-o-moon" class="lq-top-icon" x-show="$store.theme !== 'dark'" />
                    </button>
                @endif

                @if (filament()->hasUserMenu() && filament()->getUserMenuPosition() === UserMenuPosition::Topbar)
                    <x-filament-panels::user-menu />
                @endif
            @endif
        </div>

        {{ FilamentView::renderHook(PanelsRenderHook::TOPBAR_END) }}
    </nav>

    <x-filament-actions::modals />
</div>
