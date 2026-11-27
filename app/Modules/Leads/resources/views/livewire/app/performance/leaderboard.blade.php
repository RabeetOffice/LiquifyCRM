@php use App\Modules\Leads\Support\TeamPerformance; use App\Modules\Sales\Support\Money; @endphp
<div class="flex flex-col gap-6" data-live-refresh>
    @include('leads::partials.performance-head', ['title' => 'Leaderboard', 'subtitle' => 'Who is ahead this month: by % of target, then by the money received. Money counts in the month it is paid.', 'current' => 'brand.performance.leaderboard', 'month' => $shown])

    @php
        $revenue = array_sum(array_map(fn (array $row): float => $row['progress']->revenue, $rows));
        $sales = array_sum(array_map(fn (array $row): int => $row['progress']->count, $rows));
    @endphp
    <div class="lq-minis">
        <x-ui.mini label="Money received" :value="Money::format($revenue, $currency)" icon="heroicon-o-banknotes" tone="ok" />
        <x-ui.mini label="Sales closed" :value="number_format($sales)" icon="heroicon-o-trophy" />
        <x-ui.mini label="Top of the board" :value="($rows[0] ?? null) && $rows[0]['progress']->revenue > 0 ? $rows[0]['user']->name : '—'" icon="heroicon-o-star" />
    </div>

    <x-ui.card flush>
        @if ($rows === [])
            <x-ui.empty title="No sales or targets this month" description="People appear here once they have a target or a sale in the month." icon="heroicon-o-trophy" />
        @else
            <ul class="flex flex-col">
                @foreach ($rows as $index => $row)
                    <li class="lq-rank lq-perf-rank" wire:key="board-{{ $row['user']->getKey() }}">
                        <span @class(['lq-rank-place lq-tabular', 'lq-rank-first' => $index === 0 && $row['progress']->revenue > 0])>{{ $index + 1 }}</span>
                        <x-ui.avatar :name="$row['user']->name" :src="$row['user']->avatarUrl()" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center justify-between gap-3">
                                <span class="lq-cell-title truncate">{{ $row['user']->name }}</span>
                                <span class="lq-num flex-none">{{ Money::format($row['progress']->revenue, $currency) }} · {{ $row['progress']->count }} {{ str('sale')->plural($row['progress']->count) }}</span>
                            </span>
                            <x-sales::progress :progress="$row['progress']" compact class="mt-1" />
                            <span class="lq-cell-sub">{{ $row['user']->designation ? $row['user']->designation.' · ' : '' }}{{ $row['leads'] }} new {{ str('lead')->plural($row['leads']) }}{{ $row['speed'] !== null ? ' · first contact in '.TeamPerformance::minutes($row['speed']) : '' }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
