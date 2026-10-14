@php
    use App\Modules\Sales\Support\Money;
    $currency = $report->currency();
    $symbol = Money::symbol($currency);
    $minutes = fn (int $m): string => $m < 60 ? $m.' min' : ($m < 1440 ? round($m / 60, 1).' h' : round($m / 1440, 1).' days');
    $sourceColors = ['website' => '#0ea5e9', 'facebook' => '#1877f2', 'messenger' => '#a855f7', 'instagram' => '#e1306c','manual' => '#a8a29e', 'import' => '#f59e0b', 'api' => '#22c55e'];
    $personal = $report->isPersonal();
    $hasTrend = array_sum($trend['count']) > 0;
@endphp
<div class="flex flex-col gap-6" wire:poll.60s.visible data-live-refresh>
    <x-ui.page-header :title="$title" :subtitle="$personal ? 'Your sales against your target, month by month.' : ($isAdminArea ? 'One brand at a time, in its own currency. Choose a brand, then a department to look inside.' : 'Revenue and sales against target. Click a department to look inside.')">
        <x-slot:actions>
            <div class="lq-month">
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(-1)" title="Previous month"><x-heroicon-o-chevron-left class="h-5 w-5" /></button>
                <span class="lq-month-label">{{ $month->format('F Y') }}</span>
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(1)" title="Next month" @disabled($isCurrent)><x-heroicon-o-chevron-right class="h-5 w-5" /></button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Admin area: the brands, each with its own month in its own currency. --}}
    @if ($isAdminArea && count($brandCards) > 0)
        <div class="lq-brandpick" role="tablist" aria-label="Brand">
            @foreach ($brandCards as $card)
                @php $selected = $card['brand']->is($focusBrand); @endphp
                <button type="button" role="tab" aria-selected="{{ $selected ? 'true' : 'false' }}" wire:key="pick-{{ $card['brand']->getKey() }}" wire:click="drill('{{ $card['brand']->getKey() }}')" @class(['lq-brandpick-item', 'lq-brandpick-active' => $selected])>
                    <span class="flex items-center gap-2.5">
                        <x-ui.avatar :name="$card['brand']->name" :src="$card['brand']->avatarUrl()" :color="$card['brand']->primaryColor()" :tile="$card['brand']->tileColor()" size="sm" brand />
                        <span class="lq-cell-title truncate">{{ $card['brand']->name }}</span>
                    </span>
                    <span class="lq-brandpick-value lq-tabular">{{ Money::format($card['progress']->revenue, $card['progress']->currency) }}</span>
                    <x-sales::progress :progress="$card['progress']" compact />
                </button>
            @endforeach
        </div>
    @endif

    {{-- Where am I inside the brand --}}
    @if ($focusDepartment)
        <ol class="lq-breadcrumbs">
            <li><button type="button" class="lq-link" wire:click="drill('{{ $isAdminArea ? $focusBrand->getKey() : '' }}')">{{ $focusBrand->name }}</button></li>
            <li aria-hidden="true">/</li>
            <li><span>{{ $focusDepartment->name }}</span></li>
        </ol>
    @endif

    <div class="lq-grid-stats">
        <x-ui.stat :label="$personal ? 'My revenue this month' : 'Revenue this month'" :value="Money::format($totals['revenue'], $currency)" icon="heroicon-o-banknotes" hero
            :description="'Last month '.Money::format($totals['previous_revenue'], $currency)"
            :delta="$totals['change'] !== null ? ($totals['change'] >= 0 ? '+' : '').$totals['change'].'%' : null" :up="$totals['change'] !== null ? $totals['change'] >= 0 : null" />
        <x-ui.stat :label="$personal ? 'My sales' : 'Sales closed'" :value="$totals['count']" icon="heroicon-o-trophy"
            :description="$totals['upsells'] > 0
                ? ($totals['count'] - $totals['upsells']).' front, '.$totals['upsells'].' '.str('upsell')->plural($totals['upsells']).' ('.Money::format($totals['upsell_revenue'], $currency).')'
                : 'Last month '.$totals['previous_count']" />
        <x-ui.stat label="Average sale" :value="$average !== null ? Money::format(round($average), $currency) : null" icon="heroicon-o-calculator" description="Revenue divided by sales" />
        <div class="lq-stat">
            <div class="lq-stat-head">
                <span class="lq-stat-icon"><x-heroicon-o-flag /></span>
                <span class="lq-stat-label">{{ $personal ? 'My target' : 'Target' }}</span>
            </div>
            <div class="lq-stat-value-row">
                <div class="lq-stat-value">{{ $progress->hasTarget() ? $progress->percent().'%' : '—' }}</div>
            </div>
            <x-sales::progress :progress="$progress" />
        </div>
    </div>

    <div class="lq-grid-main">
        <x-ui.card :title="$personal ? 'My revenue' : 'Revenue'" description="The last six months.">
            @if (! $hasTrend)
                <x-ui.empty title="No sales in these months" description="Open a lead and click Mark as Won to record the first sale." icon="heroicon-o-chart-bar" />
            @else
                <div class="lq-chart" wire:ignore wire:key="trend-{{ md5(json_encode($trend)) }}" x-data="chart({
                    type: 'bar',
                    data: {
                        labels: @js($trend['labels']),
                        datasets: [{ label: 'Revenue', data: @js($trend['revenue']), lqFill: 'orange', lqPrefix: @js($symbol), borderRadius: 8, maxBarThickness: 44 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, border: { display: false }, ticks: { maxTicksLimit: 5 } }, x: { grid: { display: false }, border: { display: false } } } }
                })">
                    <canvas x-ref="canvas"></canvas>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Where leads came from" description="Leads of the month, by source.">
            @if ($sources === [])
                <x-ui.empty title="No leads this month" icon="heroicon-o-inbox" />
            @else
                <div class="lq-chart lq-chart-sm" wire:ignore wire:key="sources-{{ md5(json_encode(array_map(fn ($row) => [$row['source']->value, $row['leads']], $sources))) }}" x-data="chart({
                    type: 'doughnut',
                    data: {
                        labels: @js(array_map(fn ($row) => $row['source']->getLabel(), $sources)),
                        datasets: [{ data: @js(array_map(fn ($row) => $row['leads'], $sources)), backgroundColor: @js(array_map(fn ($row) => $sourceColors[$row['source']->value] ?? '#a8a29e', $sources)), borderWidth: 0, hoverOffset: 6 }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false } } }
                })">
                    <canvas x-ref="canvas"></canvas>
                </div>
                <ul class="lq-legend">
                    @foreach ($sources as $row)
                        <li>
                            <span class="lq-legend-dot" style="background: {{ $sourceColors[$row['source']->value] ?? '#a8a29e' }}"></span>
                            <span class="flex-1 truncate">{{ $row['source']->getLabel() }}</span>
                            <span class="lq-tabular lq-ink font-semibold">{{ number_format($row['leads']) }}</span>
                            <span class="lq-tabular lq-muted" title="Won {{ $row['won'] }}">{{ $row['conversion'] !== null ? $row['conversion'].'% won' : '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>

    @unless ($personal)
        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Department ranking --}}
            @if ($departmentRows !== [])
                <x-ui.card title="Departments" description="By revenue and % of target. Click one to look inside." flush>
                    <ul class="flex flex-col">
                        @foreach ($departmentRows as $index => $row)
                            <li wire:key="department-{{ $row['department']->getKey() }}">
                                <button type="button" class="lq-rank" wire:click="drill('{{ $isAdminArea ? $row['department']->brand_id : '' }}', '{{ $row['department']->getKey() }}')">
                                    <span class="lq-rank-place lq-tabular">{{ $index + 1 }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center justify-between gap-3">
                                            <span class="lq-cell-title truncate">{{ $row['department']->name }}</span>
                                            <span class="lq-num flex-none">{{ Money::format($row['progress']->revenue, $currency) }}</span>
                                        </span>
                                        <x-sales::progress :progress="$row['progress']" compact class="mt-1" />
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            {{-- Leaderboard --}}
            <x-ui.card :title="$focusDepartment ? 'People in '.$focusDepartment->name : 'Leaderboard'" description="By % of target; over-achievers first." flush>
                @if ($agentRows === [])
                    <x-ui.empty title="No sales or targets yet" description="People appear here once they have a target or their first sale this month." icon="heroicon-o-trophy" />
                @else
                    <ul class="flex flex-col">
                        @foreach ($agentRows as $index => $row)
                            <li class="lq-rank" wire:key="agent-{{ $row['user']->getKey() }}">
                                <span @class(['lq-rank-place lq-tabular', 'lq-rank-first' => $index === 0 && $row['progress']->revenue > 0])>{{ $index + 1 }}</span>
                                <x-ui.avatar :name="$row['user']->name" :src="$row['user']->avatarUrl()" size="sm" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-3">
                                        <span class="lq-cell-title truncate">{{ $row['user']->name }}</span>
                                        <span class="lq-num flex-none">{{ Money::format($row['progress']->revenue, $currency) }} · {{ $row['progress']->count }} {{ str('sale')->plural($row['progress']->count) }}</span>
                                    </span>
                                    <x-sales::progress :progress="$row['progress']" compact class="mt-1" />
                                    <span class="lq-cell-sub">{{ $row['user']->designation ? $row['user']->designation.' · ' : '' }}{{ $row['leads'] }} {{ str('lead')->plural($row['leads']) }} this month{{ $row['speed'] !== null ? ' · first contact in '.$minutes($row['speed']) : '' }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- Top clients --}}
            @if ($topClients !== [])
                <x-ui.card title="Top clients" :description="auth()->user()?->isGlobalAdmin() && ! $focusDepartment ? 'By lifetime value.' : 'By what was sold to them here.'" flush>
                    <ul class="flex flex-col">
                        @foreach ($topClients as $row)
                            <li class="lq-rank" wire:key="client-{{ $row['client']->getKey() }}">
                                <x-ui.avatar :name="$row['client']->name" size="sm" />
                                <span class="min-w-0 flex-1">
                                    @if ($canSeeClients && $row['brand'] && auth()->user()?->can('view', $row['client']))
                                        <a class="lq-cell-title" href="{{ url('/b/'.$row['brand']->slug.'/clients/'.$row['client']->getKey()) }}" wire:navigate>{{ $row['client']->name }}</a>
                                    @else
                                        <span class="lq-cell-title">{{ $row['client']->name }}</span>
                                    @endif
                                    <span class="lq-cell-sub">{{ $row['sales'] }} {{ str('sale')->plural($row['sales']) }}</span>
                                </span>
                                <span class="lq-num flex-none">{{ Money::format($row['value'], $currency) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            {{-- Speed --}}
            @if ($speed !== [])
                <x-ui.card title="Speed to first contact" description="Average time from a new lead to the first call or message.">
                    <dl class="lq-dl">
                        @foreach ($speed as $row)
                            <div><dt>{{ $row['department']->name }}</dt><dd class="lq-num">{{ $minutes($row['minutes']) }}</dd></div>
                        @endforeach
                    </dl>
                </x-ui.card>
            @endif
        </div>
    @endunless

    {{-- Sales of the month --}}
    <x-ui.card :title="$personal ? 'My sales this month' : 'Sales this month'" flush>
        @if ($recent->isEmpty())
            <x-ui.empty title="No sales recorded this month" description="Open a lead and click Mark as Won to record a sale." icon="heroicon-o-trophy" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead><tr><th>Closed</th><th>Client</th><th>Service</th><th>Closed by</th><th class="lq-table-end">Amount</th></tr></thead>
                    <tbody class="lq-live">
                        @foreach ($recent as $sale)
                            <tr wire:key="sale-{{ $sale->getKey() }}">
                                <td class="lq-muted whitespace-nowrap">{{ $sale->closed_at->format('j M') }}</td>
                                <td class="lq-cell-title">{{ $sale->client->name }}</td>
                                <td class="lq-muted">{{ $sale->service ?? '—' }} @if ($sale->isUpsell()) <x-ui.badge color="success">Upsell</x-ui.badge> @endif</td>
                                <td class="lq-muted">{{ $sale->closer?->name ?? '—' }}{{ $sale->department ? ' · '.$sale->department->name : '' }}</td>
                                <td class="lq-table-end lq-num">{{ Money::format($sale->amount, $sale->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
