{{-- Top bar over the content: menu toggle, greeting, status, search, bell, theme. The account menu lives in the sidebar. --}}
@php
    $now = \App\Modules\Core\Support\ViewerTime::now();
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

        {{-- Clocks (user requests, 20 and 21 Oct 2026): this computer's time, the brand's, and the time zones this person added
             with "+" (a brand's, or any other by search), four clocks at most, 12-hour with AM/PM. The added ones are a choice
             of this computer (localStorage lq_clocks), see `clocks` in app.js. --}}
        <div
            class="lq-clocks"
            x-data="clocks(@js(['brand' => $brand ? ['zone' => $brand->timezone, 'name' => $brand->name] : null, 'choices' => \App\Modules\Brands\Support\BrandClocks::choices($user)]))"
            x-on:click.outside="open = false"
            x-on:keydown.escape="open = false"
        >
            @php
                // Your own and the brand's clock come drawn from the server, so they stand still when a page opens;
                // app.js keeps them ticking (and corrects your own if this computer's zone is not known yet).
                $ownZone = \App\Modules\Core\Support\ViewerTime::computerZone() ?? \App\Modules\Core\Support\ViewerTime::zone($user);
                $brandCity = $brand ? \App\Modules\Core\Support\ViewerTime::city($brand->timezone) : null;
            @endphp
            <div class="lq-clock lq-clock-mine" title="Your time (this computer)">
                <span class="lq-clock-time"><span x-text="time(own)">{{ now($ownZone)->format('g:i A') }}</span></span>
                <span class="lq-clock-label" x-text="'You · ' + city(own)">You · {{ \App\Modules\Core\Support\ViewerTime::city($ownZone) }}</span>
            </div>
            @if ($brand)
                <div class="lq-clock" x-show="brand.zone !== own" @if ($brand->timezone === $ownZone) style="display: none" @endif title="{{ $brand->name }}: {{ $brandCity }} time">
                    <span class="lq-clock-time"><span x-text="time(brand.zone)">{{ now($brand->timezone)->format('g:i A') }}</span><span class="lq-clock-day" x-show="day(brand.zone)" x-text="day(brand.zone)" style="display: none"></span></span>
                    <span class="lq-clock-label">{{ $brandCity }} · brand</span>
                </div>
            @endif
            <template x-for="clock in extras" :key="clock.zone">
                <div class="lq-clock lq-clock-extra" :title="clock.title">
                    <span class="lq-clock-time"><span x-text="time(clock.zone)"></span><span class="lq-clock-day" x-show="day(clock.zone)" x-text="day(clock.zone)"></span></span>
                    <span class="lq-clock-label" x-text="clock.label"></span>
                </div>
            </template>

            <button type="button" class="lq-clock-add" x-on:click="show()" title="Add a clock" aria-haspopup="menu" x-bind:aria-expanded="open">
                <x-heroicon-m-plus />
            </button>

            <div class="lq-menu lq-clock-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu">
                <div class="lq-clock-search">
                    <x-heroicon-o-magnifying-glass />
                    <input type="text" x-ref="search" x-model="query" placeholder="Search a city or a country…" aria-label="Search a city or a country" autocomplete="off" spellcheck="false"
                        x-on:keydown.enter.prevent="results[0] && toggle(results[0].zone)">
                </div>
                <div class="lq-menu-header" x-text="query.trim() === '' ? 'Where your brands are' : 'Time zones'"></div>
                <div class="lq-clock-list">
                    <template x-for="choice in results" :key="choice.zone">
                        <button type="button" class="lq-menu-item" role="menuitemcheckbox" x-bind:aria-checked="chosen(choice.zone)" x-on:click="toggle(choice.zone)">
                            <span class="lq-clock-check" :class="chosen(choice.zone) && 'lq-clock-check-on'"><x-heroicon-m-check /></span>
                            <span class="lq-clock-choice">
                                <span x-text="choice.city"></span>
                                <span class="lq-clock-choice-sub" x-show="choice.sub" x-text="choice.sub"></span>
                            </span>
                            <span class="lq-clock-choice-time lq-tabular" x-text="time(choice.zone)"></span>
                        </button>
                    </template>
                    <p class="lq-clock-note" x-show="query.trim() !== '' && results.length === 0" x-text="loading ? 'Loading the time zones…' : 'Nothing matches. Try a city or a country.'"></p>
                </div>
                <p class="lq-clock-note lq-clock-full" x-show="full" x-cloak>Four clocks at most. Untick one to add another.</p>
                <p class="lq-clock-note">Up to four clocks, saved on this computer. Your own time comes from it too.</p>
            </div>
        </div>
    @endif

    <div class="lq-top-end">
        @if ($brand === null && $user?->isGlobalAdmin())
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

    </div>
</header>
