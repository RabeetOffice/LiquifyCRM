{{-- Top bar over the content: menu toggle, greeting, status, search, bell, theme. The account menu lives in the sidebar. --}}
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
