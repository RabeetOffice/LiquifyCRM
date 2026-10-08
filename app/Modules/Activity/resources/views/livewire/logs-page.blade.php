@php
    $bytes = fn (int $b): string => $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header title="Logs" :subtitle="$isAdmin ? 'Everything that happened: who, which brand, what, when and from where.' : 'Everything that happened in your departments.'">
        <x-slot:actions>
            @if ($canDownload)
                @foreach ($ranges as $days)
                    <x-ui.button variant="secondary" icon="heroicon-o-arrow-down-tray" wire:click="export({{ $days }})" wire:loading.attr="disabled" wire:target="export({{ $days }})">Last {{ $days }} days</x-ui.button>
                @endforeach
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($exports->isNotEmpty())
        <x-ui.card title="Your downloads" description="A CSV inside a ZIP. Each link works for 24 hours." flush>
            <ul class="flex flex-col">
                @foreach ($exports as $export)
                    <li class="lq-rank" wire:key="export-{{ $export->getKey() }}" @if ($export->status === 'pending') wire:poll.5s @endif>
                        <span class="min-w-0 flex-1">
                            <span class="lq-cell-title">Last {{ $export->range_days }} days</span>
                            <span class="lq-cell-sub">Asked {{ $export->created_at?->diffForHumans() }}{{ $export->rows !== null ? ' · '.number_format($export->rows).' '.str('entry')->plural($export->rows) : '' }}</span>
                        </span>
                        @if ($export->isReady())
                            <x-ui.button size="sm" variant="secondary" icon="heroicon-o-arrow-down-tray" :href="route('logs.exports.download', ['export' => $export->getKey()])" :navigate="false">Download</x-ui.button>
                        @elseif ($export->status === 'pending')
                            <x-ui.badge color="warning">Preparing…</x-ui.badge>
                        @elseif ($export->status === 'failed')
                            <x-ui.badge color="danger" title="{{ $export->error }}">Failed</x-ui.badge>
                        @else
                            <x-ui.badge color="gray">Expired</x-ui.badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card flush>
        <div class="lq-toolbar">
            <div class="relative w-full sm:w-64">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Lead name or email…">
            </div>
            <div class="lq-toolbar-end">
                @if ($brands->isNotEmpty())
                    <select wire:model.live="brandFilter" class="lq-input lq-input-sm" style="width: 11rem" aria-label="Brand">
                        <option value="">Every brand</option>
                        @foreach ($brands as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                @endif
                <select wire:model.live="person" class="lq-input lq-input-sm" style="width: 10rem" aria-label="Person">
                    <option value="">Everyone</option>
                    @foreach ($people as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="area" class="lq-input lq-input-sm" style="width: 9rem" aria-label="Kind of event">
                    <option value="">Every kind</option>
                    @foreach ($areas as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <input type="date" wire:model.live="from" class="lq-input lq-input-sm" style="width: 9.5rem" aria-label="From date">
                <input type="date" wire:model.live="until" class="lq-input lq-input-sm" style="width: 9.5rem" aria-label="Until date">
                @if ($filtered)
                    <x-ui.button size="sm" variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
                @endif
            </div>
        </div>

        @if ($entries->isEmpty())
            <x-ui.empty :title="$filtered ? 'Nothing matches these filters' : 'Nothing logged yet'" :description="$filtered ? 'Older entries are in the daily archive files.' : null" icon="heroicon-o-clipboard-document-list" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr><th>When</th><th>Who</th><th>What happened</th><th>Kind</th>@if ($brands->isNotEmpty())<th>Brand</th>@endif<th>From</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr class="lq-row-click" wire:key="log-{{ $entry->getKey() }}" wire:click="toggle({{ $entry->getKey() }})">
                                <td class="lq-muted whitespace-nowrap" title="{{ $entry->created_at?->diffForHumans() }}">{{ $entry->created_at?->format('j M, H:i') }}</td>
                                <td>{{ $entry->causer?->name ?? 'System' }}</td>
                                <td>
                                    <span class="lq-cell-title">{{ str($entry->description)->limit(90) }}</span>
                                    @if ($entry->department)
                                        <span class="lq-cell-sub">{{ $entry->department->name }}</span>
                                    @endif
                                </td>
                                <td><x-ui.badge color="gray">{{ $areas[$entry->log_name] ?? ucfirst((string) $entry->log_name) }}</x-ui.badge></td>
                                @if ($brands->isNotEmpty())
                                    <td class="lq-muted">{{ $entry->brand?->name ?? '—' }}</td>
                                @endif
                                <td class="lq-muted lq-tabular">{{ $entry->ip ?? '—' }}</td>
                            </tr>
                            @if ($openEntry === $entry->getKey())
                                <tr wire:key="log-detail-{{ $entry->getKey() }}">
                                    <td colspan="{{ $brands->isNotEmpty() ? 6 : 5 }}">
                                        <dl class="lq-dl">
                                            <div><dt>Event</dt><dd><code class="lq-code">{{ $entry->event }}</code></dd></div>
                                            <div><dt>Time</dt><dd>{{ $entry->created_at?->format('j M Y, H:i:s') }}</dd></div>
                                            @if ($entry->subject_type)
                                                <div><dt>About</dt><dd>{{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}</dd></div>
                                            @endif
                                            <div><dt>Browser</dt><dd class="lq-muted">{{ str((string) $entry->user_agent)->limit(110) ?: '—' }}</dd></div>
                                        </dl>
                                        @if (filled($entry->properties) && count($entry->properties) > 0)
                                            <pre class="lq-log mt-2">{{ json_encode($entry->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $entries->links('pagination.lq') }}
        @endif
    </x-ui.card>

    @if ($storage !== null)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card title="Storage" description="How much the logs take, so growth is visible before it becomes a problem.">
                <dl class="lq-dl">
                    <div><dt>Entries in the database</dt><dd class="lq-num">{{ number_format($storage['rows']) }}</dd></div>
                    <div><dt>Oldest entry</dt><dd>{{ $storage['oldest'] ? \Illuminate\Support\Carbon::parse($storage['oldest'])->format('j M Y') : '—' }}</dd></div>
                    <div><dt>Daily archive files</dt><dd class="lq-num">{{ number_format($storage['archives']) }}</dd></div>
                    <div><dt>Archive size</dt><dd class="lq-num">{{ $bytes($storage['archive_bytes']) }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card title="How long logs are kept" description="Each night older entries move to a compressed file and leave the database. A lead's own timeline is always kept with the lead.">
                @if ($isSuperAdmin)
                    <form wire:submit="saveRetention" class="lq-form-grid lq-form-grid-2">
                        <x-ui.field label="Searchable on this screen" name="databaseDays">
                            <x-ui.select wire:model="databaseDays" name="databaseDays">
                                @foreach ($databaseOptions as $days)
                                    <option value="{{ $days }}">{{ $days }} days</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Kept as archive files" name="archiveDays">
                            <x-ui.select wire:model="archiveDays" name="archiveDays">
                                @foreach ($archiveOptions as $days)
                                    <option value="{{ $days }}">{{ $days }} days</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <div class="lq-col-full flex justify-end">
                            <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="saveRetention">Save retention</x-ui.button>
                        </div>
                    </form>
                @else
                    <dl class="lq-dl">
                        <div><dt>Searchable on this screen</dt><dd>{{ $databaseDays }} days</dd></div>
                        <div><dt>Kept as archive files</dt><dd>{{ $archiveDays }} days</dd></div>
                    </dl>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card title="Daily archives" description="One compressed file per day. Use the date filters above to find an older period." flush>
            @if ($archives->isEmpty())
                <p class="lq-help px-6 py-6">No archive files{{ $from.$until !== '' ? ' in these dates' : ' yet. The first one is written tonight' }}.</p>
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table">
                        <thead><tr><th>Day</th><th class="lq-table-end">Entries</th><th class="lq-table-end">Size</th><th class="lq-table-end">File</th></tr></thead>
                        <tbody>
                            @foreach ($archives as $archive)
                                <tr wire:key="archive-{{ $archive->getKey() }}">
                                    <td>{{ $archive->date->format('j M Y') }}</td>
                                    <td class="lq-table-end lq-num">{{ number_format($archive->rows) }}</td>
                                    <td class="lq-table-end lq-num">{{ $bytes($archive->size_bytes) }}</td>
                                    <td class="lq-table-end"><x-ui.button size="sm" variant="ghost" icon="heroicon-o-arrow-down-tray" :href="route('admin.logs.archives.download', ['archive' => $archive->getKey()])" :navigate="false">Download</x-ui.button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        @if ($isSuperAdmin)
            <x-ui.card title="Delete logs" description="Removes entries before a date from this screen. They are archived first, and the last {{ $protectedDays }} days can never be deleted.">
                <form wire:submit="confirmDelete" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <x-ui.field label="Delete everything before" name="deleteBefore">
                        <x-ui.input type="date" wire:model="deleteBefore" name="deleteBefore" max="{{ now()->subDays($protectedDays)->toDateString() }}" />
                    </x-ui.field>
                    <x-ui.button type="submit" variant="danger" icon="heroicon-o-trash">Delete logs</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
