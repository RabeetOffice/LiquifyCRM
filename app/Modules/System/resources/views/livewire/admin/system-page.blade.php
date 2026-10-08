<div class="flex flex-col gap-6" @if ($polling) wire:poll.5s @endif>
    <x-ui.page-header title="System" subtitle="Version, deployments, database updates, queue and cron health, backups.">
        <x-slot:actions>
            <x-ui.button variant="ghost" wire:click="runHealthChecks" icon="heroicon-o-arrow-path">Run health checks</x-ui.button>
            @if ($status->failedJobs() > 0)
                <x-ui.button variant="ghost" wire:click="retryFailedJobs" icon="heroicon-o-bolt">Retry failed jobs</x-ui.button>
            @endif
            @if ($releases->enabled() && $releases->previousRelease() !== null)
                <x-ui.button variant="danger" wire:click="rollback" icon="heroicon-o-arrow-uturn-left">Roll back to previous release</x-ui.button>
            @endif
            <x-ui.button variant="secondary" wire:click="backupNow" icon="heroicon-o-archive-box" :disabled="$running !== null">Back up now</x-ui.button>
            <x-ui.button variant="primary" wire:click="databaseUpdate" icon="heroicon-o-circle-stack" :disabled="$running !== null">Backup and run database update</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

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
                <span>The code is live but these database changes have not been applied yet. Click <strong>Backup and run database update</strong> above.</span>
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

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Version and environment">
            <dl class="lq-dl">
                @foreach ($status->environment() as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        </x-ui.card>

        <x-ui.card title="Queue and cron">
            <dl class="lq-dl">
                <div>
                    <dt>Cron last ran</dt>
                    <dd class="flex items-center gap-2">
                        <x-ui.badge :color="$status->cronIsHealthy() ? 'success' : 'danger'">{{ $status->cronIsHealthy() ? 'Healthy' : 'Not running' }}</x-ui.badge>
                        <span>{{ $status->lastCronRun()?->diffForHumans() ?? 'never' }}</span>
                    </dd>
                </div>
                <div><dt>Jobs waiting</dt><dd>{{ $status->pendingJobs() }}</dd></div>
                <div><dt>Failed jobs</dt><dd><x-ui.badge :color="$status->failedJobs() > 0 ? 'danger' : 'success'">{{ $status->failedJobs() }}</x-ui.badge></dd></div>
            </dl>
            <p class="lq-help mt-4">Hostinger cron, every minute: <code class="lq-code">php {{ $releases->deployPath() ? $releases->deployPath().'/current' : base_path() }}/artisan schedule:run</code></p>
        </x-ui.card>

        <x-ui.card title="Health checks">
            @if ($health->isEmpty())
                <p class="lq-help">No results yet. Click <strong>Run health checks</strong> above or wait for the scheduler.</p>
            @else
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ($health as $check)
                        <li class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium">{{ $check['label'] }}</div>
                                @if ($check['message'])
                                    <div class="text-xs lq-muted">{{ $check['message'] }}</div>
                                @endif
                            </div>
                            <x-ui.badge :color="match ($check['status']) { 'ok' => 'success', 'warning' => 'warning', 'skipped' => 'gray', default => 'danger' }">{{ $check['summary'] ?: ucfirst($check['status']) }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Releases on this server" description="Deployer keeps the last releases in releases/ and points current at the live one.">
            @if (! $releases->enabled())
                <p class="lq-help">Release folders are not available on this host (localhost or a single-folder install). Rollback appears here on Hostinger.</p>
            @else
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ($releases->releases() as $release)
                        <li class="flex items-center justify-between gap-3">
                            <span class="lq-tabular">{{ $release['name'] }}</span>
                            <span class="flex items-center gap-2">
                                <span class="text-xs lq-muted">{{ \Carbon\CarbonImmutable::createFromTimestamp($release['deployed_at'])->diffForHumans() }}</span>
                                @if ($release['is_current'])
                                    <x-ui.badge color="success">current</x-ui.badge>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Latest backups" description="Database dumps on the backups disk. A backup is always taken before a database update.">
            @if ($backups->isEmpty())
                <p class="lq-help">No backups yet. Click <strong>Back up now</strong> or wait for the nightly run at 02:00.</p>
            @else
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ($backups as $backup)
                        <li class="flex items-center justify-between gap-3">
                            <span class="truncate lq-tabular">{{ basename($backup['name']) }}</span>
                            <span class="flex shrink-0 items-center gap-3 text-xs lq-muted">
                                <span>{{ $backup['size'] }}</span>
                                <span>{{ $backup['date']->diffForHumans() }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="Deployment history" description="Deploys, database updates, backups and rollbacks." flush>
        @if ($deployments->isEmpty())
            <x-ui.empty title="No deployments yet" description="The first GitHub Actions deploy and every database update will be listed here." icon="heroicon-o-rocket-launch" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Commit</th>
                            <th>Branch</th>
                            <th class="lq-table-end">Migrations</th>
                            <th>By</th>
                            <th>When</th>
                            <th class="lq-table-end">Log</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($deployments as $deployment)
                            <tr wire:key="deployment-{{ $deployment->getKey() }}">
                                <td><x-ui.badge :color="$deployment->type->getColor()" :icon="$deployment->type->getIcon()">{{ $deployment->type->getLabel() }}</x-ui.badge></td>
                                <td><x-ui.badge :color="$deployment->status->getColor()">{{ $deployment->status->getLabel() }}</x-ui.badge></td>
                                <td class="lq-tabular">{{ $deployment->commit_sha ? substr($deployment->commit_sha, 0, 7) : '—' }}</td>
                                <td>{{ $deployment->branch ?? '—' }}</td>
                                <td class="lq-table-end lq-num">{{ is_array($deployment->migrations_run) ? count($deployment->migrations_run) : 0 }}</td>
                                <td>{{ $deployment->triggeredBy?->name ?? 'GitHub Actions' }}</td>
                                <td class="lq-muted">{{ $deployment->created_at?->diffForHumans() }}</td>
                                <td class="lq-table-end"><x-ui.button size="sm" variant="ghost" wire:click="$set('viewingLog', {{ $deployment->getKey() }})">Log</x-ui.button></td>
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
