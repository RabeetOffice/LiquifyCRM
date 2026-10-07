@php
    $status = $this->getStatus();
    $releases = $this->getReleases();
    $pending = $status->pendingMigrations();
    $running = $status->runningDeployment();
    $health = $this->getHealthResults();
    $backups = $this->getBackups();
@endphp

<x-filament-panels::page>
<div class="flex flex-col gap-6" @if ($this->isPolling()) wire:poll.5s @endif>
    {{-- Pending database updates --}}
    @if ($running)
        <x-filament::section icon="heroicon-o-arrow-path" icon-color="info" heading="{{ $running->type->getLabel() }} in progress">
            <p class="text-sm" style="color: var(--lq-muted)">
                Started {{ $running->started_at?->diffForHumans() ?? 'moments ago' }}. This page refreshes every 5 seconds.
                @if (config('queue.default') === 'database')
                    On localhost run <code class="lq-tabular">php artisan queue:work --stop-when-empty</code> if nothing happens within a minute.
                @endif
            </p>
            @if ($running->log)
                <pre class="mt-3 max-h-64 overflow-auto rounded-xl p-3 text-xs" style="background: var(--lq-bg); color: var(--lq-ink)">{{ $running->log }}</pre>
            @endif
        </x-filament::section>
    @elseif (count($pending) > 0)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning" heading="{{ count($pending) }} pending database {{ Str::plural('update', count($pending)) }}">
            <p class="text-sm" style="color: var(--lq-muted)">The code is live but these database changes have not been applied yet. Click <strong>Backup and run database update</strong> above.</p>
            <ul class="mt-3 flex flex-col gap-1 text-sm">
                @foreach ($pending as $migration)
                    <li class="flex items-center gap-2"><x-filament::badge color="warning">pending</x-filament::badge><span class="lq-tabular">{{ $migration }}</span></li>
                @endforeach
            </ul>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-check-circle" icon-color="success" heading="Database is up to date">
            <p class="text-sm" style="color: var(--lq-muted)">Every migration on disk has been applied. New releases that need database changes will show an amber banner here.</p>
        </x-filament::section>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Environment --}}
        <x-filament::section heading="Version and environment" icon="heroicon-o-rocket-launch">
            <dl class="flex flex-col divide-y" style="--tw-divide-opacity: 1; border-color: var(--lq-hairline)">
                @foreach ($status->environment() as $label => $value)
                    <div class="flex items-center justify-between gap-4 py-2 text-sm">
                        <dt style="color: var(--lq-muted)">{{ $label }}</dt>
                        <dd class="lq-tabular font-medium">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        {{-- Queue and cron --}}
        <x-filament::section heading="Queue and cron" icon="heroicon-o-clock">
            <dl class="flex flex-col gap-3 text-sm">
                <div class="flex items-center justify-between gap-4">
                    <dt style="color: var(--lq-muted)">Cron last ran</dt>
                    <dd class="flex items-center gap-2">
                        <x-filament::badge :color="$status->cronIsHealthy() ? 'success' : 'danger'">
                            {{ $status->cronIsHealthy() ? 'Healthy' : 'Not running' }}
                        </x-filament::badge>
                        <span class="lq-tabular">{{ $status->lastCronRun()?->diffForHumans() ?? 'never' }}</span>
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt style="color: var(--lq-muted)">Jobs waiting</dt>
                    <dd class="lq-tabular font-medium">{{ $status->pendingJobs() }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <dt style="color: var(--lq-muted)">Failed jobs</dt>
                    <dd>
                        <x-filament::badge :color="$status->failedJobs() > 0 ? 'danger' : 'success'">{{ $status->failedJobs() }}</x-filament::badge>
                    </dd>
                </div>
            </dl>
            <p class="mt-4 text-xs" style="color: var(--lq-muted)">
                Hostinger cron, every minute: <code>php {{ $releases->deployPath() ? $releases->deployPath().'/current' : base_path() }}/artisan schedule:run</code>
            </p>
        </x-filament::section>

        {{-- Health --}}
        <x-filament::section heading="Health checks" icon="heroicon-o-shield-check">
            @if ($health->isEmpty())
                <p class="text-sm" style="color: var(--lq-muted)">No results yet. Click <strong>Run health checks</strong> above or wait for the scheduler.</p>
            @else
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ($health as $check)
                        <li class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium">{{ $check['label'] }}</div>
                                @if ($check['message'])
                                    <div class="text-xs" style="color: var(--lq-muted)">{{ $check['message'] }}</div>
                                @endif
                            </div>
                            <x-filament::badge :color="match ($check['status']) { 'ok' => 'success', 'warning' => 'warning', 'skipped' => 'gray', default => 'danger' }">
                                {{ $check['summary'] ?: ucfirst($check['status']) }}
                            </x-filament::badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Releases --}}
        <x-filament::section heading="Releases on this server" icon="heroicon-o-archive-box" description="Deployer keeps the last releases in releases/ and points current at the live one.">
            @if (! $releases->enabled())
                <p class="text-sm" style="color: var(--lq-muted)">Release folders are not available on this host (localhost or a single-folder install). Rollback appears here on Hostinger.</p>
            @else
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ($releases->releases() as $release)
                        <li class="flex items-center justify-between gap-3">
                            <span class="lq-tabular">{{ $release['name'] }}</span>
                            <span class="flex items-center gap-2">
                                <span class="text-xs" style="color: var(--lq-muted)">{{ \Carbon\CarbonImmutable::createFromTimestamp($release['deployed_at'])->diffForHumans() }}</span>
                                @if ($release['is_current'])
                                    <x-filament::badge color="success">current</x-filament::badge>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        {{-- Backups --}}
        <x-filament::section heading="Latest backups" icon="heroicon-o-circle-stack" description="Database dumps on the backups disk. A backup is always taken before a database update.">
            @if ($backups->isEmpty())
                <p class="text-sm" style="color: var(--lq-muted)">No backups yet. Click <strong>Back up now</strong> or wait for the nightly run at 02:00.</p>
            @else
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ($backups as $backup)
                        <li class="flex items-center justify-between gap-3">
                            <span class="truncate lq-tabular">{{ basename($backup['name']) }}</span>
                            <span class="flex shrink-0 items-center gap-3 text-xs" style="color: var(--lq-muted)">
                                <span>{{ $backup['size'] }}</span>
                                <span>{{ $backup['date']->diffForHumans() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>

    {{-- History --}}
    @livewire(\App\Modules\System\Filament\Admin\Widgets\DeploymentsTable::class)
</div>
</x-filament-panels::page>
