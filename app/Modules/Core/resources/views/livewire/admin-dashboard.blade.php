@php
    use App\Modules\Core\Support\OverviewCards;
    use App\Modules\Leads\Enums\LeadSource;
    use App\Modules\Sales\Support\Money;
@endphp
<div class="flex flex-col gap-6" wire:poll.60s.visible data-live-refresh>
    <x-ui.page-header title="Overview" subtitle="Every brand at a glance: this month's leads and sales, and what is waiting for somebody.">
        <x-slot:actions>
            @can('create', \App\Modules\Brands\Models\Brand::class)
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('admin.brands.create')">Add brand</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="lq-grid-stats">
        <x-ui.stat label="Active brands" :value="$brands" icon="heroicon-o-building-storefront" :description="$brands === 0 ? 'Add your first brand to begin' : 'Workspaces collecting leads'" :href="route('admin.brands.index')" link="Manage brands" hero />
        <x-ui.stat label="Leads this month" :value="number_format($leadsThisMonth)" icon="heroicon-o-inbox-arrow-down" description="All brands, against the same days last month" :delta="$leadsDelta['text'] ?? null" :up="$leadsDelta['up'] ?? null" />
        <x-ui.stat label="Sales this month" :value="number_format($sales)" icon="heroicon-o-trophy" :description="$salesSplit ?? 'Closed in all brands'" :href="route('admin.sales')" link="Sales dashboard" />
        <x-ui.stat label="Unassigned leads" :value="number_format($unassigned)" icon="heroicon-o-user-minus"
            :description="$overdue > 0 ? number_format($overdue).' '.str('follow-up')->plural($overdue).' overdue' : 'No follow-up overdue'"
            :description-color="$overdue > 0 ? 'danger' : 'success'"
            :description-icon="$overdue > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle'" />
    </div>

    {{-- Leads per day of every brand: today hour by hour, or the last 7 or 30 days. --}}
    <x-ui.card title="Leads per day" :description="$perDay['total'] === 0 ? 'Every brand, '.($perDay['hourly'] ? 'today hour by hour' : 'the last '.$perDay['days'].' days') : number_format($perDay['total']).' '.str('lead')->plural($perDay['total']).' '.($perDay['hourly'] ? 'today, hour by hour, in your time' : 'in the last '.$perDay['days'].' days, in your time')">
        <x-slot:actions>
            <div class="lq-seg" role="tablist" aria-label="Range of the chart">
                @foreach ($ranges as $key => $label)
                    <button type="button" @class(['lq-seg-item', 'lq-seg-active' => $range === (string) $key]) wire:click="$set('range', '{{ $key }}')" role="tab" aria-selected="{{ $range === (string) $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:actions>

        @if ($perDay['total'] === 0)
            <x-ui.empty :title="$perDay['hourly'] ? 'No leads yet today' : 'No leads in these days'" description="Leads from websites, Facebook, Instagram, imports and those added by hand show up here, brand by brand." icon="heroicon-o-chart-bar" />
        @else
            <div class="lq-perday">
                <div class="lq-chart" wire:ignore wire:key="perday-{{ md5(json_encode([$perDay['labels'], $perDay['datasets']])) }}" x-data="chart({
                    type: 'bar',
                    data: { labels: @js($perDay['labels']), datasets: @js($perDay['datasets']) },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: { displayColors: true, boxWidth: 10, boxHeight: 10, boxPadding: 4, filter: (item) => item.raw > 0, itemSort: (a, b) => b.raw - a.raw },
                        },
                        scales: {
                            x: { stacked: true, grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                            y: { stacked: true, beginAtZero: true, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } },
                        },
                    },
                })">
                    <canvas x-ref="canvas"></canvas>
                </div>

                <ul class="lq-perday-list" aria-label="Leads per brand">
                    @foreach ($perDay['brands'] as $row)
                        <li wire:key="perday-brand-{{ $row['brand']->getKey() }}">
                            <a href="{{ route('brand.leads.index', ['brand' => $row['brand']->slug]) }}" class="lq-perday-row" wire:navigate>
                                <span class="lq-perday-dot" style="background: {{ $row['color'] }}"></span>
                                <span class="lq-perday-name">{{ $row['brand']->name }}</span>
                                <span class="lq-perday-num">{{ number_format($row['total']) }}</span>
                                @unless ($perDay['hourly'])
                                    <span class="lq-perday-avg" title="Average per day">{{ rtrim(rtrim(number_format($row['total'] / $perDay['days'], 1), '0'), '.') }}/day</span>
                                @endunless
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="Brands this month" description="Side by side, each in its own currency. Click a number to open that list." flush>
        @if ($rows === [])
            <x-ui.empty title="No brands yet" description="Add your first brand to see its month here." icon="heroicon-o-building-storefront" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table lq-table-rows lq-glance">
                    <thead>
                        <tr>
                            <th>Brand</th>
                            <th class="lq-table-end">Leads</th>
                            <th class="lq-table-end" title="Leads nobody owns yet">Unassigned</th>
                            <th class="lq-table-end" title="Follow-ups past their date and not done">Overdue</th>
                            <th class="lq-table-end">Sales</th>
                            <th class="lq-glance-money">Revenue and target</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $brand = $row['brand'];
                                $params = ['brand' => $brand->slug];
                                $delta = $row['delta'];
                            @endphp
                            <tr wire:key="glance-{{ $brand->getKey() }}" @class(['lq-glance-off' => ! $brand->is_active])>
                                <td class="lq-td-person">
                                    <a href="{{ $brand->url() }}" class="lq-person" wire:navigate>
                                        <x-ui.avatar :name="$brand->name" :src="$brand->avatarUrl()" :color="$brand->primaryColor()" :tile="$brand->tileColor()" size="sm" brand />
                                        <span class="min-w-0">
                                            <span class="lq-cell-title">{{ $brand->name }}</span>
                                            <span class="lq-cell-sub">{{ $brand->is_active ? $brand->currency : 'Inactive · '.$brand->currency }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td class="lq-table-end">
                                    <span class="lq-glance-cell">
                                        @if ($delta)
                                            <span @class(['lq-delta', 'lq-delta-up' => $delta['up'] === true, 'lq-delta-down' => $delta['up'] === false]) title="Same days last month: {{ number_format($row['previous']) }}">{{ $delta['text'] }}</span>
                                        @endif
                                        <a href="{{ route('brand.leads.index', $params) }}" class="lq-glance-num" wire:navigate>{{ number_format($row['leads']) }}</a>
                                    </span>
                                </td>
                                <td class="lq-table-end">
                                    @if ($row['unassigned'] > 0)
                                        <a href="{{ route('brand.leads.index', [...$params, 'tab' => 'unassigned']) }}" class="lq-glance-num lq-glance-warn" wire:navigate>{{ number_format($row['unassigned']) }}</a>
                                    @else
                                        <span class="lq-glance-num lq-glance-zero">0</span>
                                    @endif
                                </td>
                                <td class="lq-table-end">
                                    @if ($row['overdue'] > 0)
                                        <a href="{{ route('brand.follow-ups', $params) }}" class="lq-glance-num lq-glance-bad" wire:navigate>{{ number_format($row['overdue']) }}</a>
                                    @else
                                        <span class="lq-glance-num lq-glance-zero">0</span>
                                    @endif
                                </td>
                                <td class="lq-table-end">
                                    <span class="lq-glance-num" @if ($row['upsells'] > 0) title="{{ $row['sales'] - $row['upsells'] }} front, {{ $row['upsells'] }} {{ str('upsell')->plural($row['upsells']) }}" @endif>{{ number_format($row['sales']) }}</span>
                                </td>
                                <td class="lq-glance-money">
                                    <a href="{{ route('admin.sales', ['b' => $brand->getKey()]) }}" class="lq-glance-revenue" wire:navigate>
                                        <span class="lq-num lq-ink font-semibold">{{ Money::format($row['progress']->revenue, $row['progress']->currency) }}</span>
                                        <x-sales::progress :progress="$row['progress']" compact />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Eight questions across brands, two cards to a row. --}}
    @php
        $days = OverviewCards::DAYS;
        $tone = fn (?int $percent): string => match (true) {
            $percent === null => '',
            $percent >= 70 => 'lq-ov-ok',
            $percent >= 40 => 'lq-ov-warn',
            default => 'lq-ov-bad',
        };
    @endphp
    <div class="lq-grid-cards">
        <x-ui.card title="First reply speed" :description="'How long a new lead waits for its first contact, last '.$days.' days'">
            @if ($cards['speed'] === [])
                <p class="lq-ov-empty"><x-heroicon-o-clock /> No leads from websites, Facebook or chats in these days.</p>
            @else
                <div class="lq-ov-list">
                    @foreach ($cards['speed'] as $row)
                        <a href="{{ route('brand.leads.index', ['brand' => $row['brand']->slug]) }}" class="lq-ov-row" wire:key="speed-{{ $row['brand']->getKey() }}" wire:navigate>
                            <x-ui.avatar :name="$row['brand']->name" :src="$row['brand']->avatarUrl()" :color="$row['brand']->primaryColor()" :tile="$row['brand']->tileColor()" size="sm" brand />
                            <span class="lq-ov-name"><span>{{ $row['brand']->name }}</span><span class="lq-ov-sub">{{ $row['contacted'] }} of {{ $row['leads'] }} {{ str('lead')->plural($row['leads']) }} contacted</span></span>
                            <span class="lq-ov-num" title="Half the leads were contacted faster than this">{{ $row['median'] !== null ? OverviewCards::duration($row['median']) : 'Not yet' }}</span>
                            <span @class(['lq-ov-side', $tone($row['quick'])]) title="Contacted within an hour">{{ $row['quick'] !== null ? $row['quick'].'% in 1 h' : '' }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Lead to sale" description="This month: leads that became a first sale, and the money per lead">
            @if ($cards['conversion'] === [])
                <p class="lq-ov-empty"><x-heroicon-o-trophy /> No leads this month yet.</p>
            @else
                <div class="lq-ov-list">
                    @foreach ($cards['conversion'] as $row)
                        <a href="{{ route('admin.sales', ['b' => $row['brand']->getKey()]) }}" class="lq-ov-row" wire:key="conversion-{{ $row['brand']->getKey() }}" wire:navigate>
                            <x-ui.avatar :name="$row['brand']->name" :src="$row['brand']->avatarUrl()" :color="$row['brand']->primaryColor()" :tile="$row['brand']->tileColor()" size="sm" brand />
                            <span class="lq-ov-name"><span>{{ $row['brand']->name }}</span><span class="lq-ov-sub">{{ $row['won'] }} {{ str('sale')->plural($row['won']) }} from {{ $row['leads'] }} {{ str('lead')->plural($row['leads']) }}</span></span>
                            <span @class(['lq-ov-num', 'lq-ov-zero' => $row['won'] === 0])>{{ rtrim(rtrim(number_format($row['rate'], 1), '0'), '.') }}%</span>
                            <span class="lq-ov-side" title="This month's money divided by this month's leads">{{ $row['won'] > 0 ? Money::format($row['perLead'], $row['currency']).' per lead' : 'No sale yet' }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Where leads come from" :description="'Each brand\'s leads by source, last '.$days.' days'">
            @if ($cards['sources']['brands'] === [])
                <p class="lq-ov-empty"><x-heroicon-o-globe-alt /> No leads in these days.</p>
            @else
                <div class="lq-src-key">
                    @foreach ($cards['sources']['sources'] as $source)
                        <span><i style="background: {{ $sourceColors[$source->value] ?? '#888780' }}"></i>{{ $source->getLabel() }}</span>
                    @endforeach
                </div>
                <div class="lq-ov-list">
                    @foreach ($cards['sources']['brands'] as $row)
                        <div class="lq-src-row" wire:key="sources-{{ $row['brand']->getKey() }}">
                            <span class="lq-src-name">{{ $row['brand']->name }}</span>
                            <span class="lq-src-bar">
                                @foreach ($row['sources'] as $source => $count)
                                    <span style="width: {{ round($count / $row['total'] * 100, 2) }}%; background: {{ $sourceColors[$source] ?? '#888780' }}" title="{{ LeadSource::from($source)->getLabel() }}: {{ $count }} ({{ round($count / $row['total'] * 100) }}%)"></span>
                                @endforeach
                            </span>
                            <span class="lq-ov-num">{{ number_format($row['total']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Busiest hours" :description="'When leads arrive, every brand, last '.$days.' days, in your time'">
            @if ($cards['hours']['total'] === 0)
                <p class="lq-ov-empty"><x-heroicon-o-clock /> No leads in these days.</p>
            @else
                @php
                    $hours = $cards['hours'];
                    $peak = collect($hours['grid'])->flatMap(fn (array $row, int $day): array => array_map(fn (int $count, int $hour): array => [$count, $day, $hour], $row, array_keys($row)))->sortByDesc(0)->first();
                    $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                @endphp
                <div class="lq-heat" role="img" aria-label="Leads by day of the week and hour; busiest {{ $dayNames[$peak[1]] }} {{ sprintf('%02d:00', $peak[2]) }}">
                    @foreach ($hours['grid'] as $day => $row)
                        <span class="lq-heat-day">{{ $dayNames[$day] }}</span>
                        @foreach ($row as $hour => $count)
                            <span @class(['lq-heat-cell', 'lq-heat-'.($count === 0 ? 0 : (int) ceil($count / max(1, $hours['max']) * 4))]) title="{{ $dayNames[$day] }} {{ sprintf('%02d:00', $hour) }}: {{ $count }} {{ str('lead')->plural($count) }}"></span>
                        @endforeach
                    @endforeach
                    <span></span>
                    @foreach ([0, 3, 6, 9, 12, 15, 18, 21] as $hour)
                        <span class="lq-heat-hour">{{ sprintf('%02d', $hour) }}</span>
                    @endforeach
                </div>
                <p class="lq-ov-note">Busiest: {{ $dayNames[$peak[1]] }} from {{ sprintf('%02d:00', $peak[2]) }}, {{ $peak[0] }} {{ str('lead')->plural($peak[0]) }} in these {{ $days }} days.</p>
            @endif
        </x-ui.card>

        <x-ui.card title="Chats waiting for a reply" :description="'Messenger and Instagram: the person wrote last, and Meta still allows a reply ('.\App\Modules\Meta\Models\Conversation::REPLY_WINDOW_HOURS.' hours)'">
            @if ($cards['chats'] === [])
                <p class="lq-ov-empty lq-ov-good"><x-heroicon-o-check-circle /> No chat is waiting for a reply.</p>
            @else
                <div class="lq-ov-list">
                    @foreach ($cards['chats'] as $row)
                        <a href="{{ route('brand.inbox', ['brand' => $row['brand']->slug, 'filter' => 'open']) }}" class="lq-ov-row" wire:key="chats-{{ $row['brand']->getKey() }}">
                            <x-ui.avatar :name="$row['brand']->name" :src="$row['brand']->avatarUrl()" :color="$row['brand']->primaryColor()" :tile="$row['brand']->tileColor()" size="sm" brand />
                            <span class="lq-ov-name"><span>{{ $row['brand']->name }}</span>
                                <span class="lq-ov-sub lq-ov-icons">
                                    @foreach (array_unique($row['channels']) as $channel)
                                        <x-ui.brand-icon :name="$channel === 'instagram' ? 'instagram' : 'messenger'" />
                                    @endforeach
                                    {{ $row['chats'] }} {{ str('chat')->plural($row['chats']) }}
                                </span>
                            </span>
                            <span @class(['lq-ov-side', 'lq-ov-bad' => $row['since']->lt(now()->subHours(4)), 'lq-ov-warn' => ! $row['since']->lt(now()->subHours(4))]) title="The longest wait">waiting {{ OverviewCards::duration(max(1, (int) $row['since']->diffInMinutes(now()))) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Follow-ups today" description="Due today and overdue, brand by brand">
            @if ($cards['followUps'] === [])
                <p class="lq-ov-empty lq-ov-good"><x-heroicon-o-check-circle /> Nothing due today and nothing overdue.</p>
            @else
                <div class="lq-ov-list">
                    @foreach ($cards['followUps'] as $row)
                        <a href="{{ route('brand.follow-ups', ['brand' => $row['brand']->slug, 'tab' => $row['overdue'] > 0 ? 'overdue' : 'today']) }}" class="lq-ov-row" wire:key="followups-{{ $row['brand']->getKey() }}" wire:navigate>
                            <x-ui.avatar :name="$row['brand']->name" :src="$row['brand']->avatarUrl()" :color="$row['brand']->primaryColor()" :tile="$row['brand']->tileColor()" size="sm" brand />
                            <span class="lq-ov-name"><span>{{ $row['brand']->name }}</span></span>
                            <span class="lq-ov-num">{{ $row['today'] }} today</span>
                            <span @class(['lq-ov-side', 'lq-ov-bad' => $row['overdue'] > 0])>{{ $row['overdue'] > 0 ? $row['overdue'].' overdue' : 'none overdue' }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Websites that went quiet" description="Switched on, but no lead for 2 days, or a check failed">
            @if ($cards['sites'] === [])
                <p class="lq-ov-empty lq-ov-good"><x-heroicon-o-check-circle /> Every website is sending leads.</p>
            @else
                <div class="lq-ov-list">
                    @foreach ($cards['sites'] as $row)
                        @php $favicon = $row['site']->faviconUrl(); @endphp
                        <a href="{{ route('brand.sites.show', ['brand' => $row['brand']->slug, 'site' => $row['site']->getKey()]) }}" class="lq-ov-row" wire:key="site-{{ $row['site']->getKey() }}" wire:navigate>
                            <span class="lq-ov-site">
                                @if ($favicon)
                                    <img src="{{ $favicon }}" alt="" loading="lazy">
                                @else
                                    <x-heroicon-o-globe-alt />
                                @endif
                            </span>
                            <span class="lq-ov-name"><span>{{ $row['site']->domain }}</span><span class="lq-ov-sub">{{ $row['brand']->name }}</span></span>
                            <span @class(['lq-ov-side', 'lq-ov-bad' => $row['failed'], 'lq-ov-warn' => ! $row['failed']])>
                                @if ($row['failed'])
                                    <x-heroicon-m-exclamation-triangle class="lq-ov-inline" />
                                @endif
                                {{ $row['reason'] }}
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Top sellers this month" description="Across brands; money in each brand's own currency">
            @if ($cards['sellers'] === [])
                <p class="lq-ov-empty"><x-heroicon-o-trophy /> No sale recorded this month yet.</p>
            @else
                <div class="lq-ov-list">
                    @foreach ($cards['sellers'] as $index => $row)
                        <a href="{{ route('admin.sales', ['b' => $row['brand']->getKey()]) }}" class="lq-ov-row" wire:key="seller-{{ $row['user']->getKey() }}-{{ $row['brand']->getKey() }}" wire:navigate>
                            <span class="lq-ov-rank">{{ $index + 1 }}</span>
                            <x-ui.avatar :name="$row['user']->name" :src="$row['user']->avatarUrl()" size="sm" />
                            <span class="lq-ov-name"><span>{{ $row['user']->name }}</span><span class="lq-ov-sub">{{ $row['brand']->name }}</span></span>
                            <span class="lq-ov-num">{{ $row['sales'] }} {{ str('sale')->plural($row['sales']) }}</span>
                            <span class="lq-ov-side">{{ Money::format($row['revenue'], (string) $row['brand']->currency) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="Recent activity" description="Latest entries in the audit trail" flush>
        @if ($activity->isEmpty())
            <p class="lq-help px-6 py-6">Nothing logged yet.</p>
        @else
            <ul class="flex flex-col lq-live">
                @foreach ($activity as $entry)
                    <li class="flex items-start gap-3 px-6 py-3" style="border-top: 1px solid var(--lq-card-border)" wire:key="activity-{{ $entry->getKey() }}">
                        <x-ui.avatar :name="$entry->causer?->name ?? 'System'" size="sm" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm">{{ $entry->description }}</div>
                            <div class="text-xs lq-muted">{{ $entry->causer?->name ?? 'System' }}{{ $entry->brand ? ' · '.$entry->brand->name : '' }} · {{ $entry->created_at?->diffForHumans() }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- The installed version: a quiet line at the very bottom. --}}
    <a href="{{ route('admin.updates') }}" @class(['lq-versionbar', 'lq-versionbar-due' => $update['available']]) wire:navigate>
        <x-heroicon-o-arrow-up-circle class="lq-versionbar-icon" />
        <span class="lq-versionbar-name">LiquifyCRM {{ $update['version'] }}</span>
        <span class="lq-versionbar-state">
            @if ($update['available'])
                <x-heroicon-m-exclamation-triangle /> An update is ready to apply
            @else
                <x-heroicon-m-check-circle /> Up to date
            @endif
        </span>
        <span class="lq-versionbar-link">{{ $update['available'] ? 'Update system' : 'See what changed' }} <x-heroicon-m-arrow-right /></span>
    </a>
</div>
