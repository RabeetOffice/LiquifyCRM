@php
    use App\Modules\Sales\Support\Money;
    $currency = $report->currency();
    $minutes = fn (int $m): string => $m < 60 ? $m.' min' : ($m < 1440 ? round($m / 60, 1).' h' : round($m / 1440, 1).' days');
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$title" :subtitle="$report->isPersonal() ? 'Your sales and target for the month.' : ($isAdminArea && ! $focusBrand ? 'All brands, shown in '.$currency.'. Click a row to look inside.' : 'Revenue and sales against target. Click a row to look inside.')">
        <x-slot:actions>
            <div class="lq-month">
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(-1)" title="Previous month"><x-heroicon-o-chevron-left class="h-5 w-5" /></button>
                <span class="lq-month-label">{{ $month->format('F Y') }}</span>
                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="shift(1)" title="Next month" @disabled($isCurrent)><x-heroicon-o-chevron-right class="h-5 w-5" /></button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Where am I: All brands / Brand / Department --}}
    @if (($isAdminArea && $focusBrand) || $focusDepartment)
        <ol class="lq-breadcrumbs">
            @if ($isAdminArea)
                <li><button type="button" class="lq-link" wire:click="drill">All brands</button></li>
                <li aria-hidden="true">/</li>
            @endif
            @if ($focusBrand)
                <li>
                    @if ($focusDepartment)
                        <button type="button" class="lq-link" wire:click="drill('{{ $isAdminArea ? $focusBrand->getKey() : '' }}')">{{ $focusBrand->name }}</button>
                    @else
                        <span>{{ $focusBrand->name }}</span>
                    @endif
                </li>
            @endif
            @if ($focusDepartment)
                <li aria-hidden="true">/</li>
                <li><span>{{ $focusDepartment->name }}</span></li>
            @endif
        </ol>
    @endif

    @if ($missingRates > 0)
        <div class="lq-alert lq-alert-warning">
            <x-heroicon-o-exclamation-triangle />
            <span>{{ $missingRates }} {{ str('sale')->plural($missingRates) }} this month {{ $missingRates === 1 ? 'is' : 'are' }} not in the totals because no exchange rate to {{ $currency }} is entered. <a class="lq-link" href="{{ route('admin.exchange-rates') }}" wire:navigate>Enter exchange rates</a></span>
        </div>
    @endif

    <div class="lq-grid-stats lq-grid-stats-3">
        <x-ui.stat label="Revenue this month" :value="Money::format($totals['revenue'], $currency)" icon="heroicon-o-banknotes" hero
            :description="'Last month '.Money::format($totals['previous_revenue'], $currency)"
            :delta="$totals['change'] !== null ? ($totals['change'] >= 0 ? '+' : '').$totals['change'].'%' : null" :up="$totals['change'] !== null ? $totals['change'] >= 0 : null" />
        <x-ui.stat label="Sales this month" :value="$totals['count']" icon="heroicon-o-trophy" :description="'Last month '.$totals['previous_count']" />
        <x-ui.card>
            <p class="lq-stat-label mb-3">{{ $report->isPersonal() ? 'My target' : 'Target' }}</p>
            <x-sales::progress :progress="$progress" />
        </x-ui.card>
    </div>

    {{-- Brand performance --}}
    @if ($brandRows !== [])
        <x-ui.card title="Brand performance" description="Leads, conversion, revenue and target for each brand." flush>
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead><tr><th>Brand</th><th class="lq-table-end">Leads</th><th class="lq-table-end">Conversion</th><th class="lq-table-end">Revenue</th><th style="min-width: 14rem">Target</th></tr></thead>
                    <tbody>
                        @foreach ($brandRows as $row)
                            <tr class="lq-row-click" wire:key="brand-{{ $row['brand']->getKey() }}" wire:click="drill('{{ $row['brand']->getKey() }}')">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$row['brand']->name" :src="$row['brand']->avatarUrl()" :color="$row['brand']->primaryColor()" size="sm" brand />
                                        <span class="lq-cell-title">{{ $row['brand']->name }}</span>
                                    </div>
                                </td>
                                <td class="lq-table-end lq-num">{{ number_format($row['leads']) }}</td>
                                <td class="lq-table-end lq-num">{{ $row['conversion'] !== null ? $row['conversion'].'%' : '—' }}</td>
                                <td class="lq-table-end lq-num">{{ Money::format($row['progress']->revenue, $currency) }}</td>
                                <td><x-sales::progress :progress="$row['progress']" compact /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Department ranking --}}
        @if ($departmentRows !== [])
            <x-ui.card title="Department ranking" description="By revenue and % of target." flush>
                <ul class="flex flex-col">
                    @foreach ($departmentRows as $index => $row)
                        <li wire:key="department-{{ $row['department']->getKey() }}">
                            <button type="button" class="lq-rank" wire:click="drill('{{ $isAdminArea ? $row['department']->brand_id : '' }}', '{{ $row['department']->getKey() }}')">
                                <span class="lq-rank-place lq-tabular">{{ $index + 1 }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-3">
                                        <span class="lq-cell-title truncate">{{ $row['department']->name }}@if ($isAdminArea && ! $focusBrand && $row['brand']) <span class="lq-muted font-normal">· {{ $row['brand']->name }}</span>@endif</span>
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

        {{-- Agent leaderboard --}}
        @if (! $report->isPersonal())
            <x-ui.card :title="$focusDepartment ? 'People in '.$focusDepartment->name : 'Leaderboard'" description="By % of target; over-achievers first." flush>
                @if ($agentRows === [])
                    <x-ui.empty title="No sales or targets yet" description="People appear here once they have a target or their first sale this month." icon="heroicon-o-trophy" />
                @else
                    <ul class="flex flex-col">
                        @foreach ($agentRows as $index => $row)
                            <li class="lq-rank" wire:key="agent-{{ $row['user']->getKey() }}">
                                <span class="lq-rank-place lq-tabular">{{ $index + 1 }}</span>
                                <x-ui.avatar :name="$row['user']->name" :src="$row['user']->avatarUrl()" size="sm" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-3">
                                        <span class="lq-cell-title truncate">{{ $row['user']->name }}</span>
                                        <span class="lq-num flex-none">{{ Money::format($row['progress']->revenue, $currency) }} · {{ $row['progress']->count }} {{ str('sale')->plural($row['progress']->count) }}</span>
                                    </span>
                                    <x-sales::progress :progress="$row['progress']" compact class="mt-1" />
                                    <span class="lq-cell-sub">{{ $row['leads'] }} {{ str('lead')->plural($row['leads']) }} this month{{ $row['speed'] !== null ? ' · first contact in '.$minutes($row['speed']) : '' }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @endif

        {{-- Top clients --}}
        @if ($topClients !== [])
            <x-ui.card title="Top clients" description="By lifetime value." flush>
                <ul class="flex flex-col">
                    @foreach ($topClients as $row)
                        <li class="lq-rank" wire:key="client-{{ $row['client']->getKey() }}">
                            <x-ui.avatar :name="$row['client']->name" size="sm" />
                            <span class="min-w-0 flex-1">
                                @if ($canSeeClients && $row['brand'])
                                    <a class="lq-cell-title" href="{{ url('/b/'.$row['brand']->slug.'/clients/'.$row['client']->getKey()) }}" wire:navigate>{{ $row['client']->name }}</a>
                                @else
                                    <span class="lq-cell-title">{{ $row['client']->name }}</span>
                                @endif
                                <span class="lq-cell-sub">{{ $row['client']->sales_count }} {{ str('sale')->plural($row['client']->sales_count) }}{{ $isAdminArea && $row['brand'] ? ' · '.$row['brand']->name : '' }}</span>
                            </span>
                            <span class="lq-num flex-none">{{ Money::format($row['value'], $currency) }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        {{-- Lead sources --}}
        <x-ui.card title="Lead sources" description="Leads that arrived this month and how many became sales." flush>
            @if ($sources === [])
                <x-ui.empty title="No leads this month" icon="heroicon-o-inbox" />
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table">
                        <thead><tr><th>Source</th><th class="lq-table-end">Leads</th><th class="lq-table-end">Won</th><th class="lq-table-end">Conversion</th></tr></thead>
                        <tbody>
                            @foreach ($sources as $row)
                                <tr>
                                    <td><x-ui.badge :color="$row['source']->getColor()" :icon="$row['source']->getIcon()">{{ $row['source']->getLabel() }}</x-ui.badge></td>
                                    <td class="lq-table-end lq-num">{{ number_format($row['leads']) }}</td>
                                    <td class="lq-table-end lq-num">{{ number_format($row['won']) }}</td>
                                    <td class="lq-table-end lq-num">{{ $row['conversion'] !== null ? $row['conversion'].'%' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{-- Speed --}}
        @if ($speed !== [])
            <x-ui.card title="Speed" description="Average time from a new lead to first contact.">
                <dl class="lq-dl">
                    @foreach ($speed as $row)
                        <div><dt>{{ $row['department']->name }}</dt><dd class="lq-num">{{ $minutes($row['minutes']) }}</dd></div>
                    @endforeach
                </dl>
            </x-ui.card>
        @endif
    </div>

    {{-- Recent sales --}}
    <x-ui.card :title="$report->isPersonal() ? 'My sales this month' : 'Sales this month'" flush>
        @if ($recent->isEmpty())
            <x-ui.empty title="No sales recorded this month" description="Open a lead and click Mark as Won to record a sale." icon="heroicon-o-trophy" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead><tr><th>Closed</th><th>Client</th><th>Service</th><th>Closed by</th><th class="lq-table-end">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($recent as $sale)
                            <tr wire:key="sale-{{ $sale->getKey() }}">
                                <td class="lq-muted whitespace-nowrap">{{ $sale->closed_at->format('j M') }}</td>
                                <td class="lq-cell-title">{{ $sale->client->name }}</td>
                                <td class="lq-muted">{{ $sale->service ?? '—' }}</td>
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
