@php use App\Modules\Sales\Support\Money; @endphp
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

    <div class="lq-grid-main">
        <x-ui.card title="Leads per brand" description="Last 30 days">
            @if ($chart['values'] === [])
                <x-ui.empty title="No leads yet" description="Leads from websites, Facebook, imports and those added by hand show up here, brand by brand." icon="heroicon-o-chart-bar" />
            @else
                <div class="lq-chart" wire:ignore wire:key="brands-{{ md5(json_encode($chart)) }}" x-data="chart({
                    type: 'bar',
                    data: {
                        labels: @js($chart['labels']),
                        datasets: [{ label: 'Leads', data: @js($chart['values']), lqFill: 'orange', borderRadius: 8, maxBarThickness: 56 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } }, x: { grid: { display: false }, border: { display: false } } } }
                })">
                    <canvas x-ref="canvas"></canvas>
                </div>
            @endif
        </x-ui.card>

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
    </div>

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
