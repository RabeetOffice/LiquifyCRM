<div class="flex flex-col gap-6" data-live-refresh>
    @include('leads::partials.performance-head', ['title' => 'Lost reasons', 'subtitle' => 'Why each person\'s leads were lost: lost leads last worked in the month, by the reason on the lead.', 'current' => 'brand.performance.lost', 'month' => $shown])

    @php
        $totals = [];
        foreach ($reasons as $key => $label) {
            $totals[$key] = (int) $rows->sum(fn (array $row): int => $row['counts'][$key]);
        }
        $common = array_filter($totals);
        arsort($common);
        $top = array_key_first($common);
    @endphp
    <div class="lq-minis">
        <x-ui.mini label="Leads lost" :value="number_format(array_sum($totals))" icon="heroicon-o-x-circle" />
        <x-ui.mini label="Most common reason" :value="$top !== null ? $reasons[$top] : '—'" icon="heroicon-o-light-bulb" />
    </div>

    <x-ui.card flush>
        <div class="lq-table-wrap">
            <table class="lq-table lq-table-rows lq-takes-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th class="lq-num">Lost</th>
                        @foreach ($reasons as $label)
                            <th class="lq-num">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="lost-{{ $row['id'] }}">
                            @include('leads::partials.performance-person')
                            <td class="lq-num"><strong>{{ number_format($row['total']) }}</strong></td>
                            @foreach ($reasons as $key => $label)
                                <td class="lq-num">
                                    @if ($row['counts'][$key] > 0)
                                        {{ number_format($row['counts'][$key]) }}
                                    @else
                                        <span class="lq-muted">0</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
