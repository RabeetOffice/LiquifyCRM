@php
    $environment = $status->environment();
    $cronOk = $status->cronIsHealthy();
    $failed = $status->failedJobs();
    $lastBackup = $backups->first();
    $cron = 'php '.($releases->deployPath() ? $releases->deployPath().'/current' : base_path()).'/artisan schedule:run';

    // What the mark of a health check looks like, by its result.
    $marks = [
        'ok' => 'heroicon-m-check-circle',
        'warn' => 'heroicon-m-exclamation-triangle',
        'fail' => 'heroicon-m-x-circle',
    ];
@endphp
<div class="flex flex-col gap-6" @if ($polling) wire:poll.5s @endif>
    <x-ui.page-header title="System" subtitle="Version, database updates, the scheduler and queue, health checks and backups.">
        <x-slot:actions>
            <x-ui.button variant="ghost" wire:click="runHealthChecks" icon="heroicon-o-arrow-path">Run health checks</x-ui.button>
            @if ($failed > 0)
                <x-ui.button variant="ghost" wire:click="retryFailedJobs" icon="heroicon-o-bolt">Retry failed jobs</x-ui.button>
            @endif
            @if ($releases->enabled() && $releases->previousRelease() !== null)
                <x-ui.button variant="danger" wire:click="rollback" icon="heroicon-o-arrow-uturn-left">Roll back to previous release</x-ui.button>
            @endif
            <x-ui.button variant="secondary" wire:click="backupNow" icon="heroicon-o-archive-box" :disabled="$running !== null">Back up now</x-ui.button>
            <x-ui.button variant="primary" wire:click="databaseUpdate" icon="heroicon-o-circle-stack" :disabled="$running !== null">Backup and run database update</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="lq-minis">
        <x-ui.mini label="LiquifyCRM version" :value="$environment['App version']" icon="heroicon-o-arrow-up-circle" :href="route('admin.updates')" hint="See what changed" />
        <x-ui.mini label="Scheduler" :value="$cronOk ? 'Running' : 'Not running'" icon="heroicon-o-clock" :tone="$cronOk ? 'ok' : 'fail'" :hint="'Last run: '.($status->lastCronRun()?->diffForHumans() ?? 'never')" />
        <x-ui.mini label="Jobs" :value="$status->pendingJobs().' waiting'.($failed > 0 ? ', '.$failed.' failed' : '')" icon="heroicon-o-queue-list" :tone="$failed > 0 ? 'fail' : 'ok'" />
        <x-ui.mini label="Last backup" :value="$lastBackup ? $lastBackup['date']->diffForHumans() : 'None yet'" icon="heroicon-o-archive-box" :tone="$lastBackup ? null : 'warn'" :hint="$lastBackup ? basename($lastBackup['name']) : null" />
    </div>

    @if ($running)
        <x-ui.card :title="$running->type->getLabel().' in progress'">
            <p class="text-sm lq-muted">
                Started {{ $running->started_at?->diffForHumans() ?? 'moments ago' }}. This page refreshes every 5 seconds.
                @if (config('queue.default') === 'database')
                    On localhost run <code class="lq-code">php artisan queue:work --stop-when-empty</code> if nothing happens within a minute.
                @endif
            </p>
            @if ($running->log)
                <pre class="lq-log mt-3">{{ $running->log }}</pre>
            @endif
        </x-ui.card>
    @elseif (count($pending) > 0)
        <x-ui.card :title="count($pending).' pending database '.\Illuminate\Support\Str::plural('update', count($pending))">
            <div class="lq-alert lq-alert-warning">
                <x-heroicon-o-exclamation-triangle />
                <span>The code is live but these database changes have not been applied yet. Open <a class="lq-link" href="{{ route('admin.updates') }}" wire:navigate>Updates</a> and click <strong>Update system</strong>, or use the button above.</span>
            </div>
            <ul class="mt-3 flex flex-col gap-1 text-sm">
                @foreach ($pending as $migration)
                    <li class="flex items-center gap-2"><x-ui.badge color="warning">pending</x-ui.badge><span class="lq-tabular">{{ $migration }}</span></li>
                @endforeach
            </ul>
        </x-ui.card>
    @else
        <div class="lq-alert lq-alert-success">
            <x-heroicon-o-check-circle />
            <span><strong>Database is up to date.</strong> Every migration on disk has been applied. New releases that need database changes will show an amber banner here.</span>
        </div>
    @endif

    {{-- Health checks as tiles --}}
    <div>
        <h2 class="lq-section-title">Health checks</h2>
        <p class="lq-section-text">The scheduler runs them by itself; "Run health checks" runs them now.</p>
    </div>

    @if ($health->isEmpty())
        <x-ui.card>
            <p class="lq-help">No results yet. Click <strong>Run health checks</strong> above or wait for the scheduler.</p>
        </x-ui.card>
    @else
        <div class="lq-probes lq-probes-auto">
            @foreach ($health as $check)
                @php $result = match ($check['status']) { 'ok' => 'ok', 'warning' => 'warn', 'skipped' => 'unknown', default => 'fail' }; @endphp
                <article @class(['lq-probe', 'lq-probe-'.$result]) wire:key="health-{{ $loop->index }}">
                    <header class="lq-probe-head">
                        <h3 class="lq-probe-title">{{ $check['label'] }}</h3>
                        <span class="lq-probe-mark">@svg($marks[$result] ?? 'heroicon-m-minus-circle')</span>
                    </header>
                    <p class="lq-probe-status">{{ $check['summary'] ?: ucfirst($check['status']) }}</p>
                    @if ($check['message'])
                        <p class="lq-probe-text">{{ $check['message'] }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Scheduler and queue" description="One cron line on the hosting runs everything: the queue, health checks, website checks and the nightly backup.">
            <dl class="lq-kv">
                <div>
                    <dt>Cron last ran</dt>
                    <dd class="flex items-center justify-end gap-2">
                        <x-ui.badge :color="$cronOk ? 'success' : 'danger'">{{ $cronOk ? 'Healthy' : 'Not running' }}</x-ui.badge>
                        <span>{{ $status->lastCronRun()?->diffForHumans() ?? 'never' }}</span>
                    </dd>
                </div>
                <div><dt>Jobs waiting</dt><dd class="lq-tabular">{{ $status->pendingJobs() }}</dd></div>
                <div><dt>Failed jobs</dt><dd><x-ui.badge :color="$failed > 0 ? 'danger' : 'success'">{{ $failed }}</x-ui.badge></dd></div>
            </dl>

            <div class="lq-copyrow mt-4" x-data="copy(@js($cron))">
                <span class="lq-copyrow-label">Cron, every minute</span>
                <span class="lq-copyrow-value" title="{{ $cron }}">{{ $cron }}</span>
                <button type="button" class="lq-btn lq-btn-secondary lq-btn-sm" x-on:click="copy()">
                    <x-heroicon-o-clipboard-document x-show="! copied" />
                    <x-heroicon-o-check x-show="copied" x-cloak />
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>
        </x-ui.card>

        <x-ui.card title="Version and environment">
            <dl class="lq-kv lq-kv-two">
                @foreach ($environment as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        </x-ui.card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Latest backups" description="Database dumps on the backups disk. A backup is always taken before a database update." flush>
            @if ($backups->isEmpty())
                <p class="lq-help px-6 pb-6">No backups yet. Click <strong>Back up now</strong> or wait for the nightly run at 02:00.</p>
            @else
                <ul class="lq-linklist">
                    @foreach ($backups as $backup)
                        <li class="lq-linkrow">
                            <span class="lq-feed-icon"><x-heroicon-o-archive-box /></span>
                            <span class="min-w-0 flex-1">
                                <span class="lq-cell-title truncate lq-tabular">{{ basename($backup['name']) }}</span>
                                <span class="lq-cell-sub">{{ $backup['date']->diffForHumans() }}</span>
                            </span>
                            <span class="lq-pill lq-tabular">{{ $backup['size'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Releases on this server" description="Deployer keeps the last releases in releases/ and points current at the live one." flush>
            @if (! $releases->enabled())
                <p class="lq-help px-6 pb-6">Release folders are not used on this host: the site deploys into one folder from GitHub. New versions are applied on the <a class="lq-link" href="{{ route('admin.updates') }}" wire:navigate>Updates</a> screen.</p>
            @else
                <ul class="lq-linklist">
                    @foreach ($releases->releases() as $release)
                        <li class="lq-linkrow">
                            <span class="lq-feed-icon"><x-heroicon-o-rocket-launch /></span>
                            <span class="min-w-0 flex-1">
                                <span class="lq-cell-title lq-tabular">{{ $release['name'] }}</span>
                                <span class="lq-cell-sub">{{ \Carbon\CarbonImmutable::createFromTimestamp($release['deployed_at'])->diffForHumans() }}</span>
                            </span>
                            @if ($release['is_current'])
                                <x-ui.badge color="success">current</x-ui.badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="History" description="Updates, database updates, backups, deploys and rollbacks." flush>
        @if ($deployments->isEmpty())
            <x-ui.empty title="Nothing yet" description="The first update, backup or deploy will be listed here." icon="heroicon-o-rocket-launch" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table lq-table-rows">
                    <thead>
                        <tr>
                            <th>What</th>
                            <th>Result</th>
                            <th class="lq-col-wide">Database changes</th>
                            <th>By</th>
                            <th>When</th>
                            <th class="lq-table-end">Log</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deployments as $deployment)
                            @php $changes = is_array($deployment->migrations_run) ? count($deployment->migrations_run) : 0; @endphp
                            <tr wire:key="deployment-{{ $deployment->getKey() }}">
                                <td>
                                    <x-ui.badge :color="$deployment->type->getColor()" :icon="$deployment->type->getIcon()">{{ $deployment->type->getLabel() }}</x-ui.badge>
                                    @if ($deployment->release || $deployment->commit_sha)
                                        <span class="lq-cell-sub lq-tabular">{{ $deployment->release ? 'Version '.$deployment->release : substr((string) $deployment->commit_sha, 0, 7) }}{{ $deployment->branch ? ' · '.$deployment->branch : '' }}</span>
                                    @endif
                                </td>
                                <td><x-ui.badge :color="$deployment->status->getColor()">{{ $deployment->status->getLabel() }}</x-ui.badge></td>
                                <td class="lq-col-wide lq-muted">{{ $changes > 0 ? $changes.' applied' : 'None' }}</td>
                                <td>{{ $deployment->triggeredBy?->name ?? 'GitHub Actions' }}</td>
                                <td class="lq-muted whitespace-nowrap" title="{{ $deployment->created_at?->format('j M Y, H:i') }}">{{ $deployment->created_at?->diffForHumans() }}</td>
                                <td class="lq-table-end"><x-ui.button size="sm" variant="ghost" icon="heroicon-o-document-text" wire:click="$set('viewingLog', {{ $deployment->getKey() }})">Log</x-ui.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $deployments->links('pagination.lq') }}
        @endif
    </x-ui.card>

    @if ($log)
        <x-ui.modal model="viewingLog" :title="$log->type->getLabel().' · '.$log->created_at?->format('d M Y H:i')" size="lg">
            <pre class="lq-log" style="max-height: 60vh">{{ $log->log ?? 'No log recorded.' }}</pre>
        </x-ui.modal>
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
