<div class="flex flex-col gap-6" data-live-refresh>
    @include('leads::partials.performance-head', ['title' => 'Activity', 'subtitle' => 'What each person did in the month on the leads and chats of this brand.', 'current' => 'brand.performance.activity', 'month' => $shown])

    @php
        $columns = [
            'notes' => 'Notes written',
            'done' => 'Follow-ups done',
            'overdue' => 'Follow-ups overdue',
            'replies' => 'Chat replies',
            'moves' => 'Stage moves',
            'taken' => 'Leads taken',
        ];
        $sum = fn (string $key): int => array_sum(array_column(array_column($rows, 'n'), $key));
    @endphp
    <div class="lq-minis">
        <x-ui.mini label="Notes written" :value="number_format($sum('notes'))" icon="heroicon-o-pencil-square" />
        <x-ui.mini label="Follow-ups done" :value="number_format($sum('done'))" icon="heroicon-o-check-circle" tone="ok" />
        <x-ui.mini label="Follow-ups overdue" :value="number_format($sum('overdue'))" icon="heroicon-o-exclamation-triangle" :tone="$sum('overdue') > 0 ? 'warn' : null" />
        <x-ui.mini label="Chat replies" :value="number_format($sum('replies'))" icon="heroicon-o-chat-bubble-left-right" />
    </div>

    <x-ui.card flush>
        <div class="lq-table-wrap">
            <table class="lq-table lq-table-rows lq-takes-table">
                <thead>
                    <tr>
                        <th>Person</th>
                        @foreach ($columns as $label)
                            <th class="lq-num">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="activity-{{ $row['id'] }}">
                            @include('leads::partials.performance-person')
                            @foreach ($columns as $key => $label)
                                <td class="lq-num">
                                    @if ($key === 'overdue' && $row['n'][$key] > 0)
                                        <x-ui.badge color="warning">{{ number_format($row['n'][$key]) }}</x-ui.badge>
                                    @elseif ($row['n'][$key] > 0)
                                        {{ number_format($row['n'][$key]) }}
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
