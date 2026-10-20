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

        {{-- Clocks (user request, 20 Oct 2026): this computer's time, the brand's, and the brand time zones this person added
             with "+". The added ones are a choice of this computer (localStorage lq_clocks), see `clocks` in app.js. --}}
        <div
            class="lq-clocks"
            x-data="clocks(@js(['brand' => $brand ? ['zone' => $brand->timezone, 'name' => $brand->name] : null, 'choices' => \App\Modules\Brands\Support\BrandClocks::choices($user)]))"
            x-on:click.outside="open = false"
            x-on:keydown.escape="open = false"
        >
            <template x-for="clock in clocks" :key="clock.zone">
                <div class="lq-clock" :class="clock.mine && 'lq-clock-mine'" :title="clock.title">
                    <span class="lq-clock-time"><span x-text="time(clock.zone)"></span><span class="lq-clock-day" x-show="day(clock.zone)" x-text="day(clock.zone)"></span></span>
                    <span class="lq-clock-label" x-text="clock.label"></span>
                </div>
            </template>

            <button type="button" class="lq-clock-add" x-on:click="open = ! open" title="Add the time of another brand" aria-haspopup="menu" x-bind:aria-expanded="open">
                <x-heroicon-m-plus />
            </button>

            <div class="lq-menu lq-clock-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu">
                <div class="lq-menu-header">Show the time where your brands are</div>
                <template x-for="choice in choices" :key="choice.zone">
                    <button type="button" class="lq-menu-item" role="menuitemcheckbox" x-bind:aria-checked="chosen(choice.zone)" x-on:click="toggle(choice.zone)">
                        <span class="lq-clock-check" :class="chosen(choice.zone) && 'lq-clock-check-on'"><x-heroicon-m-check /></span>
                        <span class="lq-clock-choice">
                            <span x-text="choice.city"></span>
                            <span class="lq-clock-choice-sub" x-show="choice.brands" x-text="choice.brands"></span>
                        </span>
                        <span class="lq-clock-choice-time lq-tabular" x-text="time(choice.zone)"></span>
                    </button>
                </template>
                <p class="lq-clock-note">Saved on this computer. Your own time comes from it too.</p>
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
