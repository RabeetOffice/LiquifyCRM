<div class="flex flex-col gap-6" data-live-refresh>
    @include('leads::partials.performance-head', ['title' => 'Conversion', 'subtitle' => 'Of the leads that arrived in the month and are with each person: how many were won, lost or are still open.', 'current' => 'brand.performance.conversion', 'month' => $shown])

    @php
        $sum = fn (string $key): int => array_sum(array_column(array_column($rows, 'n'), $key));
        $leads = $sum('leads');
    @endphp
    <div class="lq-minis">
        <x-ui.mini label="Leads" :value="number_format($leads)" icon="heroicon-o-inbox-arrow-down" />
        <x-ui.mini label="Won" :value="number_format($sum('won'))" icon="heroicon-o-trophy" tone="ok" />
        <x-ui.mini label="Lost" :value="number_format($sum('lost'))" icon="heroicon-o-x-circle" />
        <x-ui.mini label="Conversion" :value="$leads > 0 ? round($sum('won') / $leads * 100).'%' : '—'" icon="heroicon-o-arrow-trending-up" />
    </div>

    <x-ui.card flush>
        <div class="lq-table-wrap">
            <table class="lq-table lq-table-rows lq-takes-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th class="lq-num">Leads</th>
                        <th class="lq-num">Won</th>
                        <th class="lq-num">Lost</th>
                        <th class="lq-num">Still open</th>
                        <th class="lq-perf-rate">Conversion</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="conversion-{{ $row['id'] }}">
                            @include('leads::partials.performance-person')
                            <td class="lq-num">{{ number_format($row['n']['leads']) }}</td>
                            <td class="lq-num">
                                @if ($row['n']['won'] > 0)
                                    <x-ui.badge color="success">{{ number_format($row['n']['won']) }}</x-ui.badge>
                                @else
                                    <span class="lq-muted">0</span>
                                @endif
                            </td>
                            <td class="lq-num">{{ number_format($row['n']['lost']) }}</td>
                            <td class="lq-num">{{ number_format($row['n']['open']) }}</td>
                            <td class="lq-perf-rate">
                                @if ($row['n']['rate'] !== null)
                                    <span class="lq-perf-rate-in">
                                        <span class="lq-perf-bar"><span style="width: {{ $row['n']['rate'] }}%"></span></span>
                                        <span class="lq-num">{{ $row['n']['rate'] }}%</span>
                                    </span>
                                @else
                                    <span class="lq-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
