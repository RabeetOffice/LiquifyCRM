@php
    $changeCount = count($pending);
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header title="Updates" subtitle="What changed in LiquifyCRM, and the button that applies a new version. Only Admins see this screen." />

    {{-- Where this server stands --}}
    @if ($available)
        <section class="lq-update">
            <span class="lq-update-mark"><x-heroicon-o-arrow-up-circle /></span>
            <div class="lq-update-body">
                <h2 class="lq-update-title">Version {{ $codeVersion }} is ready</h2>
                <p class="lq-update-text">
                    @if ($installed !== null && $installed !== $codeVersion)
                        This server is on version {{ $installed }}.
                    @elseif ($installed === null)
                        The new code is on the server and waits for you to apply it.
                    @else
                        Version {{ $codeVersion }} brought more database changes.
                    @endif
                    @if ($changeCount > 0)
                        {{ $changeCount }} database {{ str('change')->plural($changeCount) }} will be applied after a backup.
                    @else
                        No database changes are needed.
                    @endif
                </p>
                @if ($changeCount > 0)
                    <details class="lq-update-details">
                        <summary>Show the database changes</summary>
                        <ul>
                            @foreach ($pending as $migration)
                                <li class="lq-tabular">{{ $migration }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
            <x-ui.button variant="primary" icon="heroicon-o-arrow-down-tray" wire:click="update" wire:loading.attr="disabled" wire:target="update, runConfirmed">Update system</x-ui.button>
        </section>
    @else
        <div class="lq-alert lq-alert-success">
            <x-heroicon-o-check-circle />
            <span>
                <strong>LiquifyCRM is up to date.</strong>
                Version {{ $codeVersion }}{{ $lastUpdate ? ', updated '.$lastUpdate->finished_at?->diffForHumans().($lastUpdate->triggeredBy ? ' by '.$lastUpdate->triggeredBy->name : '') : '' }}.
                When new code arrives, an “Update system” button appears here.
            </span>
        </div>
    @endif

    <div class="lq-alert" wire:loading.flex wire:target="runConfirmed" style="display: none">
        <x-heroicon-o-arrow-path class="animate-spin" />
        <span>Updating the system. Keep this page open; it takes a few seconds.</span>
    </div>

    @if ($failure)
        <div class="lq-alert lq-alert-danger">
            <x-heroicon-o-exclamation-triangle />
            <span class="min-w-0 flex-1">
                <strong>The last update did not finish.</strong>
                {{ $backupFailed ? 'The database backup could not be made on this server, so nothing was changed.' : 'The database was left as it was before the step that failed.' }}
                <span class="mt-2 flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="secondary" wire:click="showLog({{ $failure->getKey() }})">{{ $log && $log->is($failure) ? 'Hide the details' : 'Show the details' }}</x-ui.button>
                    @if ($backupFailed && $available)
                        <x-ui.button size="sm" variant="danger" wire:click="updateWithoutBackup">Update without a backup</x-ui.button>
                    @endif
                </span>
            </span>
        </div>
    @endif

    @if ($log)
        <x-ui.card :title="'Details of the update on '.$log->created_at?->format('j M Y, H:i')">
            <x-slot:actions>
                <x-ui.button size="sm" variant="ghost" wire:click="showLog(null)">Close</x-ui.button>
            </x-slot:actions>
            <pre class="lq-log">{{ $log->log }}</pre>
        </x-ui.card>
    @endif

    <div class="lq-grid-main">
        {{-- What's new --}}
        <x-ui.card title="What’s new" description="Every version, newest first.">
            @if ($releases === [])
                <x-ui.empty title="No versions listed yet" icon="heroicon-o-sparkles" />
            @else
                <ol class="lq-guide">
                    @foreach ($releases as $release)
                        @php
                            $isNew = in_array($release['version'], $newVersions, true) || ($installed === null && $loop->first && $available);
                            $isCurrent = $installed === $release['version'];
                        @endphp
                        <li @class(['lq-guide-step', 'lq-guide-current' => $isNew, 'lq-guide-done' => ! $isNew && $installed !== null]) wire:key="release-{{ $release['version'] }}">
                            <span class="lq-guide-mark">
                                @if ($isNew)
                                    <x-heroicon-m-sparkles />
                                @elseif ($installed !== null)
                                    <x-heroicon-m-check />
                                @else
                                    <x-heroicon-m-tag />
                                @endif
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="lq-guide-title" style="margin-top: 0.3125rem">{{ $release['title'] }}</h3>
                                    <span class="lq-kbd lq-tabular" style="margin-top: 0.3125rem">{{ $release['version'] }}</span>
                                    @if ($isNew)
                                        <x-ui.badge color="primary" style="margin-top: 0.3125rem">Waiting to be applied</x-ui.badge>
                                    @elseif ($isCurrent)
                                        <x-ui.badge color="success" style="margin-top: 0.3125rem">Installed</x-ui.badge>
                                    @endif
                                </div>
                                <p class="lq-guide-text" style="margin-top: 0.125rem">{{ $release['date']->format('j F Y') }}</p>

                                <ul class="lq-changes">
                                    @foreach ($release['changes'] as $change)
                                        <li>
                                            <x-ui.badge :color="$types[$change['type']]['color']">{{ $types[$change['type']]['label'] }}</x-ui.badge>
                                            <span>{{ $change['text'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.card>

        {{-- History --}}
        <x-ui.card title="Update history" description="When the system was updated, and by whom." flush>
            @if ($history->isEmpty())
                <x-ui.empty title="No update recorded yet" description="The first time you click Update system it appears here." icon="heroicon-o-clock" />
            @else
                <ul class="flex flex-col">
                    @foreach ($history as $entry)
                        @php $applied = count($entry->migrations_run ?? []); @endphp
                        <li class="lq-rank" wire:key="history-{{ $entry->getKey() }}">
                            <span @class(['lq-logo-tile lq-logo-sm', 'lq-badge-'.$entry->status->getColor()]) style="box-shadow: none">@svg($entry->status->getIcon())</span>
                            <span class="min-w-0 flex-1">
                                <span class="lq-cell-title">{{ $entry->release ? 'Version '.$entry->release : $entry->type->getLabel() }}</span>
                                <span class="lq-cell-sub">
                                    {{ $entry->created_at?->diffForHumans() }}{{ $entry->triggeredBy ? ' · '.$entry->triggeredBy->name : '' }}
                                    · {{ $entry->status === \App\Modules\System\Enums\DeploymentStatus::Success ? ($applied > 0 ? $applied.' database '.str('change')->plural($applied) : 'no database changes') : $entry->status->getLabel() }}
                                </span>
                            </span>
                            <x-ui.button size="sm" variant="ghost" wire:click="showLog({{ $entry->getKey() }})">Details</x-ui.button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    <x-ui.confirm :confirm="$confirm" />
</div>
