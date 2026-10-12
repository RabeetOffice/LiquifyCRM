@php
    $bytes = fn (int $b): string => $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
    $previousDay = null;
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header title="Logs" :subtitle="$isAdmin ? 'Everything that happened: who, which brand, what, when and from where.' : 'Everything that happened in your departments.'">
        <x-slot:actions>
            @if ($canDownload)
                <div class="relative" x-data="dropdown" x-on:click.outside="close()" x-on:keydown.escape="close()">
                    <x-ui.button variant="secondary" icon="heroicon-o-arrow-down-tray" x-on:click="toggle()" aria-haspopup="menu" x-bind:aria-expanded="open">Download</x-ui.button>
                    <div class="lq-menu lq-menu-end" style="top: calc(100% + 0.5rem); min-width: 13rem;" x-show="open" x-cloak role="menu">
                        <div class="lq-menu-header">A CSV in a ZIP, ready in a moment</div>
                        @foreach ($ranges as $days)
                            <button type="button" class="lq-menu-item" role="menuitem" wire:click="export({{ $days }})" x-on:click="close()">
                                <x-heroicon-o-calendar-days /> Last {{ $days }} days
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($exports->isNotEmpty())
        <x-ui.card title="Your downloads" description="Each link works for 24 hours." flush>
            <ul class="lq-linklist">
                @foreach ($exports as $export)
                    <li class="lq-linkrow" wire:key="export-{{ $export->getKey() }}" @if ($export->status === 'pending') wire:poll.5s @endif>
                        <span class="lq-feed-icon"><x-heroicon-o-archive-box-arrow-down /></span>
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

    <x-ui.card flush class="lq-list">
        {{-- The kind of event as views; who, which brand and the dates behind Filters. --}}
        <div class="lq-viewbar">
            <div class="lq-views" role="tablist" aria-label="Kind of event">
                <button type="button" @class(['lq-view', 'lq-view-active' => $area === '']) wire:click="$set('area', '')" role="tab">Everything</button>
                @foreach ($areas as $value => $label)
                    <button type="button" @class(['lq-view', 'lq-view-active' => $area === $value]) wire:click="$set('area', '{{ $value }}')" role="tab">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="lq-viewbar lq-viewbar-tools">
            <label class="lq-search">
                <x-heroicon-o-magnifying-glass />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Lead name or email" aria-label="Search by lead">
            </label>
            <div class="lq-viewbar-end">
                <button type="button" @class(['lq-btn lq-btn-secondary lq-btn-sm', 'lq-btn-on' => $showFilters || $chips !== []]) wire:click="$toggle('showFilters')" aria-expanded="{{ $showFilters ? 'true' : 'false' }}">
                    <x-heroicon-o-adjustments-horizontal />
                    Filters
                    @if ($chips !== [])
                        <span class="lq-count">{{ count($chips) }}</span>
                    @endif
                </button>
            </div>
        </div>

        @if ($showFilters)
            <div class="lq-filters">
                @if ($brands->isNotEmpty())
                    <x-ui.field label="Brand">
                        <x-ui.select wire:model.live="brandFilter" placeholder="Every brand" class="lq-input-sm">
                            @foreach ($brands as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
                <x-ui.field label="Who">
                    <x-ui.select wire:model.live="person" placeholder="Everyone" class="lq-input-sm">
                        @foreach ($people as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="From">
                    <x-ui.input type="date" wire:model.live="from" class="lq-input-sm" />
                </x-ui.field>
                <x-ui.field label="Until">
                    <x-ui.input type="date" wire:model.live="until" class="lq-input-sm" />
                </x-ui.field>
            </div>
        @endif

        @if ($chips !== [])
            <div class="lq-chips">
                @foreach ($chips as $key => $label)
                    <button type="button" class="lq-chip" wire:click="removeFilter('{{ $key }}')" wire:key="chip-{{ $key }}" title="Remove this filter">
                        {{ $label }}
                        <x-heroicon-m-x-mark />
                    </button>
                @endforeach
                <button type="button" class="lq-chip-clear" wire:click="clearFilters">Clear all</button>
            </div>
        @endif

        @if ($entries->isEmpty())
            <x-ui.empty :title="$filtered ? 'Nothing matches these filters' : 'Nothing logged yet'" :description="$filtered ? 'Older entries are in the daily archive files.' : null" icon="heroicon-o-clipboard-document-list">
                @if ($filtered)
                    <x-slot:actions>
                        <x-ui.button variant="secondary" wire:click="clearFilters">Clear filters</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.empty>
        @else
            {{-- One line per event, grouped by day; a click opens the details. --}}
            <ol class="lq-feed">
                @foreach ($entries as $entry)
                    @php
                        $day = $entry->created_at?->toDateString();
                        $open = $openEntry === $entry->getKey();
                    @endphp

                    @if ($day !== $previousDay)
                        <li class="lq-feed-day" wire:key="day-{{ $day }}">
                            {{ match (true) { $entry->created_at === null => 'Undated', $entry->created_at->isToday() => 'Today', $entry->created_at->isYesterday() => 'Yesterday', default => $entry->created_at->format($entry->created_at->isCurrentYear() ? 'l, j F' : 'j F Y') } }}
                        </li>
                        @php $previousDay = $day; @endphp
                    @endif

                    <li @class(['lq-feed-item', 'lq-feed-item-open' => $open]) wire:key="log-{{ $entry->getKey() }}">
                        <button type="button" class="lq-feed-row" wire:click="toggle({{ $entry->getKey() }})" aria-expanded="{{ $open ? 'true' : 'false' }}">
                            <span @class(['lq-feed-icon', 'lq-feed-icon-'.$entry->log_name])>@svg($areaIcons[$entry->log_name] ?? 'heroicon-o-bolt')</span>
                            <span class="lq-feed-body">
                                <span class="lq-feed-title">{{ str($entry->description)->limit(110) }}</span>
                                <span class="lq-feed-meta">{{ collect([$entry->causer?->name ?? 'System', $entry->department?->name, $brands->isNotEmpty() ? $entry->brand?->name : null])->filter()->implode(' · ') }}</span>
                            </span>
                            <span class="lq-feed-side">
                                <x-ui.badge color="gray">{{ $areas[$entry->log_name] ?? ucfirst((string) $entry->log_name) }}</x-ui.badge>
                                <span class="lq-feed-time lq-tabular" title="{{ $entry->created_at?->format('j M Y, H:i:s') }}">{{ $entry->created_at?->format('H:i') }}</span>
                                <x-heroicon-m-chevron-down class="lq-feed-chevron" />
                            </span>
                        </button>

                        @if ($open)
                            <div class="lq-feed-detail">
                                <dl class="lq-dl">
                                    <div><dt>Event</dt><dd><code class="lq-code">{{ $entry->event }}</code></dd></div>
                                    <div><dt>Time</dt><dd>{{ $entry->created_at?->format('j M Y, H:i:s') }}</dd></div>
                                    @if ($entry->subject_type)
                                        <div><dt>About</dt><dd>{{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}</dd></div>
                                    @endif
                                    <div><dt>From</dt><dd class="lq-tabular">{{ $entry->ip ?? '—' }}</dd></div>
                                    <div><dt>Browser</dt><dd class="lq-muted">{{ str((string) $entry->user_agent)->limit(110) ?: '—' }}</dd></div>
                                </dl>
                                @if (filled($entry->properties) && count($entry->properties) > 0)
                                    <pre class="lq-log mt-3">{{ json_encode($entry->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
            {{ $entries->links('pagination.lq') }}
        @endif
    </x-ui.card>

    @if ($storage !== null)
        <div>
            <h2 class="lq-section-title">Storage</h2>
            <p class="lq-section-text">How much the logs take, so growth is visible before it becomes a problem.</p>
        </div>

        <div class="lq-minis">
            <x-ui.mini label="Entries in the database" :value="number_format($storage['rows'])" icon="heroicon-o-circle-stack" />
            <x-ui.mini label="Oldest entry" :value="$storage['oldest'] ? \Illuminate\Support\Carbon::parse($storage['oldest'])->format('j M Y') : '—'" icon="heroicon-o-clock" />
            <x-ui.mini label="Daily archive files" :value="number_format($storage['archives'])" icon="heroicon-o-archive-box" />
            <x-ui.mini label="Archive size" :value="$bytes($storage['archive_bytes'])" icon="heroicon-o-server-stack" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
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
                    <dl class="lq-kv">
                        <div><dt>Searchable on this screen</dt><dd>{{ $databaseDays }} days</dd></div>
                        <div><dt>Kept as archive files</dt><dd>{{ $archiveDays }} days</dd></div>
                    </dl>
                @endif
            </x-ui.card>

            @if ($isSuperAdmin)
                <x-ui.card title="Delete logs" description="Removes entries before a date from this screen. They are archived first, and the last {{ $protectedDays }} days can never be deleted." class="lq-card-danger">
                    <form wire:submit="confirmDelete" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <x-ui.field label="Delete everything before" name="deleteBefore" class="flex-1">
                            <x-ui.input type="date" wire:model="deleteBefore" name="deleteBefore" max="{{ now()->subDays($protectedDays)->toDateString() }}" />
                        </x-ui.field>
                        <x-ui.button type="submit" variant="danger" icon="heroicon-o-trash">Delete logs</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>

        <x-ui.card title="Daily archives" description="One compressed file per day. Use the dates under Filters to find an older period." flush>
            @if ($archives->isEmpty())
                <p class="lq-help px-6 py-6">No archive files{{ $from.$until !== '' ? ' in these dates' : ' yet. The first one is written tonight' }}.</p>
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table lq-table-rows">
                        <thead><tr><th>Day</th><th class="lq-table-end">Entries</th><th class="lq-table-end">Size</th><th class="lq-table-end">File</th></tr></thead>
                        <tbody>
                            @foreach ($archives as $archive)
                                <tr wire:key="archive-{{ $archive->getKey() }}">
                                    <td>{{ $archive->date->format('l, j M Y') }}</td>
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
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
