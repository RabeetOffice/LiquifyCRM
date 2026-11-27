<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header title="Leads taken" subtitle="How many leads each person took for themselves with the Take button. Click a number to see those leads." />

    <div class="lq-minis">
        <x-ui.mini label="Taken today" :value="number_format($totals['today'])" icon="heroicon-o-sun" />
        <x-ui.mini :label="'Taken in '.$month" :value="number_format($totals['month'])" icon="heroicon-o-calendar-days" tone="ok" />
        <x-ui.mini :label="'Taken in '.$lastMonth" :value="number_format($totals['last_month'])" icon="heroicon-o-calendar" />
        <x-ui.mini label="Taken in all" :value="number_format($totals['all'])" icon="heroicon-o-hand-raised" />
    </div>

    <x-ui.card flush>
        @if ($rows->isEmpty())
            <x-ui.empty title="Nobody to show yet" description="People who work leads in this brand appear here with the leads they took." icon="heroicon-o-hand-raised" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table lq-table-rows lq-takes-table">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th class="lq-num">Today</th>
                            <th class="lq-num">{{ $month }}</th>
                            <th class="lq-num">{{ $lastMonth }}</th>
                            <th class="lq-num">All time</th>
                            <th class="lq-num">Still with them</th>
                            <th class="lq-num">Won</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $link = fn (string $period = ''): string => route('brand.leads.index', array_filter(['brand' => $brand->slug, 'taken' => $row['id'], 'taken_in' => $period]));
                            @endphp
                            <tr wire:key="taker-{{ $row['id'] }}">
                                <td class="lq-td-person">
                                    <span class="lq-person">
                                        <x-ui.avatar :name="$row['name']" :src="$row['picture']" />
                                        <span class="min-w-0">
                                            <span class="lq-cell-title">{{ $row['name'] }}@if ($row['me']) <span class="lq-muted">(you)</span>@endif</span>
                                            @if ($row['team'] !== '')
                                                <span class="lq-cell-sub">{{ $row['team'] }}</span>
                                            @endif
                                        </span>
                                    </span>
                                </td>
                                @foreach (['today' => 'today', 'month' => 'month', 'last_month' => 'last-month', 'all' => ''] as $key => $period)
                                    <td class="lq-num">
                                        @if ($row['counts'][$key] > 0)
                                            <a href="{{ $link($period) }}" class="lq-take-count" wire:navigate title="See these leads">{{ number_format($row['counts'][$key]) }}</a>
                                        @else
                                            <span class="lq-muted">0</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="lq-num">{{ number_format($row['counts']['theirs']) }}</td>
                                <td class="lq-num">
                                    @if ($row['counts']['won'] > 0)
                                        <x-ui.badge color="success">{{ number_format($row['counts']['won']) }}</x-ui.badge>
                                    @else
                                        <span class="lq-muted">0</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
