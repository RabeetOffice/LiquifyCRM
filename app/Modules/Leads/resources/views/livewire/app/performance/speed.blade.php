@php use App\Modules\Leads\Support\TeamPerformance; @endphp
<div class="flex flex-col gap-6" data-live-refresh>
    @include('leads::partials.performance-head', ['title' => 'Response speed', 'subtitle' => 'For the new leads of the month: how long until somebody had them, and until the first contact (the lead moved past New).', 'current' => 'brand.performance.speed', 'month' => $shown])

    @php
        $leads = array_sum(array_column(array_column($rows, 'n'), 'leads'));
        $quick = array_sum(array_column(array_column($rows, 'n'), 'quick'));
        $waiting = array_sum(array_column(array_column($rows, 'n'), 'waiting'));
    @endphp
    <div class="lq-minis">
        <x-ui.mini label="New leads with someone" :value="number_format($leads)" icon="heroicon-o-inbox-arrow-down" />
        <x-ui.mini :label="'Contacted within '.TeamPerformance::QUICK_MINUTES.' min'" :value="$leads > 0 ? round($quick / $leads * 100).'%' : '—'" icon="heroicon-o-bolt" tone="ok" />
        <x-ui.mini label="Still not contacted" :value="number_format($waiting)" icon="heroicon-o-clock" :tone="$waiting > 0 ? 'warn' : null" />
    </div>

    <x-ui.card flush>
        <div class="lq-table-wrap">
            <table class="lq-table lq-table-rows lq-takes-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th class="lq-num">New leads</th>
                        <th class="lq-num">Until it was theirs</th>
                        <th class="lq-num">Until first contact</th>
                        <th class="lq-num">Within {{ TeamPerformance::QUICK_MINUTES }} min</th>
                        <th class="lq-num">Not contacted yet</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="speed-{{ $row['id'] }}">
                            @include('leads::partials.performance-person')
                            <td class="lq-num">{{ number_format($row['n']['leads']) }}</td>
                            <td class="lq-num">{{ TeamPerformance::minutes($row['n']['to_owner']) }}</td>
                            <td class="lq-num"><strong>{{ TeamPerformance::minutes($row['n']['to_contact']) }}</strong></td>
                            <td class="lq-num">{{ $row['n']['leads'] > 0 ? round($row['n']['quick'] / $row['n']['leads'] * 100).'%' : '—' }}</td>
                            <td class="lq-num">
                                @if ($row['n']['waiting'] > 0)
                                    <x-ui.badge color="warning">{{ number_format($row['n']['waiting']) }}</x-ui.badge>
                                @else
                                    <span class="lq-muted">0</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
