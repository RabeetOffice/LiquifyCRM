<div class="flex flex-col gap-6">
    <x-ui.page-header title="Dashboard" :subtitle="'Here is the summary for '.$brand->name.'.'">
        <x-slot:actions>
            @can('create', \App\Modules\Leads\Models\Lead::class)
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('brand.leads.create', ['brand' => $brand->slug])">Add lead</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($checklist !== null && $checklist['done'] < $checklist['total'])
        <section class="lq-setup" aria-labelledby="lq-setup-title">
            <div class="min-w-0">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="lq-setup-title" class="lq-card-title">Set up {{ $brand->name }}</h2>
                        <p class="lq-card-desc">Each step ticks itself when it is finished.</p>
                    </div>
                    <span class="lq-pill lq-pill-orange lq-tabular">{{ $checklist['done'] }} of {{ $checklist['total'] }} done</span>
                </div>

                <div class="lq-progress my-4" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $checklist['percent'] }}" aria-label="Setup progress">
                    <div class="lq-progress-bar" style="width: {{ $checklist['percent'] }}%"></div>
                </div>

                <ol class="lq-steps">
                    @foreach ($checklist['steps'] as $step)
                        <li @class(['lq-step', 'lq-step-done' => $step['done'], 'lq-step-soon' => filled($step['soon'])])>
                            <span class="lq-step-mark" aria-hidden="true">
                                @if ($step['done'])
                                    <x-heroicon-m-check />
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </span>
                            <div class="lq-step-body">
                                <div class="lq-step-title">
                                    <span>{{ $step['title'] }}</span>
                                    @if ($step['soon'])
                                        <span class="lq-pill">Coming in {{ $step['soon'] }}</span>
                                    @endif
                                </div>
                                <p class="lq-step-desc">{{ $step['description'] }}</p>
                            </div>
                            @if ($step['url'])
                                <div class="self-center">
                                    <x-ui.button size="sm" :href="$step['url']">{{ $step['done'] ? 'View' : 'Open' }}</x-ui.button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>

            <div class="lq-setup-art" aria-hidden="true">
                <img src="{{ asset('images/brand/onboarding-welcome.webp') }}" alt="" loading="lazy" width="1200" height="800">
            </div>
        </section>
    @endif

    <div class="lq-grid-stats">
        <x-ui.stat label="New leads today" :value="$todayCount" icon="heroicon-o-sparkles" description="Compared with yesterday" :delta="$todayDelta['text'] ?? null" :up="$todayDelta['up'] ?? null" hero />
        <x-ui.stat label="Last 7 days" :value="$weekCount" icon="heroicon-o-calendar" description="Compared with the week before" :delta="$weekDelta['text'] ?? null" :up="$weekDelta['up'] ?? null" />
        @if ($agent)
            <x-ui.stat label="My open leads" :value="$myOpen" icon="heroicon-o-inbox-arrow-down" description="Assigned to you and not closed" :href="route('brand.leads.index', ['brand' => $brand->slug])" link="Open my leads" />
        @else
            <x-ui.stat label="Unassigned" :value="$unassigned" icon="heroicon-o-inbox-arrow-down" description="Waiting in the New / Unassigned queue" :href="route('brand.leads.index', ['brand' => $brand->slug, 'tab' => 'unassigned'])" link="Open the queue" />
        @endif
        <x-ui.stat label="Overdue follow-ups" :value="$overdue" icon="heroicon-o-calendar-days" :description="$overdue > 0 ? 'Past their date and not done' : 'Nothing overdue'" :description-color="$overdue > 0 ? 'danger' : 'success'" :href="route('brand.follow-ups', ['brand' => $brand->slug])" link="See follow-ups" />
    </div>

    <div class="lq-grid-main">
        @if ($agent)
            <x-ui.card title="Follow-ups due today" description="Oldest first" flush>
                @if ($followUps->isEmpty())
                    <x-ui.empty title="Nothing due today" description="Set a follow-up on a lead and it shows up here on the day." icon="heroicon-o-check-circle" />
                @else
                    <ul class="flex flex-col">
                        @foreach ($followUps as $task)
                            <li class="flex items-center gap-3 px-6 py-3" style="border-top: 1px solid var(--lq-hairline)">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ $task->lead ? route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $task->lead]) : '#' }}" class="lq-cell-title" wire:navigate>{{ $task->lead?->name ?? 'Task' }}</a>
                                    <span class="lq-cell-sub">{{ $task->title }} · {{ $task->due_at->format('H:i') }}</span>
                                </div>
                                @if ($task->isOverdue())
                                    <x-ui.badge color="danger">Overdue</x-ui.badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        @else
            <x-ui.card title="New / Unassigned" description="Oldest first. Pick an Agent to assign in one click." flush>
                <x-slot:actions>
                    <x-ui.button size="sm" :href="route('brand.leads.index', ['brand' => $brand->slug, 'tab' => 'unassigned'])">All unassigned</x-ui.button>
                </x-slot:actions>

                @if ($queue->isEmpty())
                    <x-ui.empty title="The queue is empty" description="Every lead has an owner. New leads land here first." icon="heroicon-o-check-circle" />
                @else
                    <div class="lq-table-wrap">
                        <table class="lq-table">
                            <thead>
                                <tr>
                                    <th>Lead</th>
                                    <th>Source</th>
                                    <th>Waiting</th>
                                    <th class="lq-table-end">Assign to</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($queue as $lead)
                                    <tr wire:key="queue-{{ $lead->getKey() }}">
                                        <td>
                                            <a href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $lead]) }}" class="lq-cell-title" wire:navigate>{{ $lead->name }}</a>
                                            <span class="lq-cell-sub">{{ $lead->department?->name ?? 'No department yet' }}</span>
                                        </td>
                                        <td><x-ui.badge :color="$lead->source_type->getColor()" :icon="$lead->source_type->getIcon()">{{ $lead->source_type->getLabel() }}</x-ui.badge></td>
                                        <td class="lq-num">{{ $lead->created_at?->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</td>
                                        <td class="lq-table-end">
                                            @can('assign', $lead)
                                                <select class="lq-input lq-input-sm" style="width: 11rem" wire:change="assign({{ $lead->getKey() }}, $event.target.value)">
                                                    <option value="">Choose an Agent…</option>
                                                    @foreach ($agents as $id => $name)
                                                        <option value="{{ $id }}">{{ $name }}</option>
                                                    @endforeach
                                                </select>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        @endif

        <x-ui.card title="Conversion" :description="$conversion !== null ? $won.' won of '.$total.' leads' : 'No leads yet'">
            <div class="flex items-baseline gap-2">
                <span class="lq-stat-value">{{ $conversion !== null ? $conversion.'%' : '—' }}</span>
                <span class="lq-muted text-sm">of leads marked Won</span>
            </div>
            @if ($chartStatus['values'] !== [] && array_sum($chartStatus['values']) > 0)
                <div class="lq-chart mt-4" style="height: 11rem" wire:ignore x-data="chart({
                    type: 'bar',
                    data: { labels: @js($chartStatus['labels']), datasets: [{ data: @js($chartStatus['values']), backgroundColor: @js($chartStatus['colors']), borderRadius: 6, maxBarThickness: 28 }] },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } } }
                })">
                    <canvas x-ref="canvas"></canvas>
                </div>
            @endif
        </x-ui.card>
    </div>

    <div class="lq-grid-main">
        <x-ui.card title="Leads per day" description="Last 30 days">
            <div class="lq-chart" wire:ignore x-data="chart({
                type: 'line',
                data: { labels: @js($chartDays['labels']), datasets: [{ label: 'Leads', data: @js($chartDays['values']), borderColor: '#ff6b2c', backgroundColor: 'rgba(255, 107, 44, 0.12)', fill: true, tension: 0.35, pointRadius: 2, borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } } } }
            })">
                <canvas x-ref="canvas"></canvas>
            </div>
        </x-ui.card>

        <x-ui.card title="By source" description="Last 30 days">
            @if ($chartSource['values'] === [])
                <p class="lq-help">No leads in the last 30 days.</p>
            @else
                <div class="lq-chart" wire:ignore x-data="chart({
                    type: 'doughnut',
                    data: { labels: @js($chartSource['labels']), datasets: [{ data: @js($chartSource['values']), backgroundColor: ['#ff6b2c', '#0369a1', '#6d28d9', '#15803d', '#a16207', '#57534e'], borderWidth: 0 }] },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom' } } }
                })">
                    <canvas x-ref="canvas"></canvas>
                </div>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="Recent activity" description="Latest entries in this brand's audit trail" flush>
        @if ($activity->isEmpty())
            <p class="lq-help px-6 py-6">Nothing logged yet.</p>
        @else
            <ul class="flex flex-col">
                @foreach ($activity as $entry)
                    <li class="flex items-start gap-3 px-6 py-3" style="border-top: 1px solid var(--lq-hairline)">
                        <x-ui.avatar :name="$entry->causer?->name ?? 'System'" size="sm" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm">{{ $entry->description }}</div>
                            <div class="text-xs lq-muted">{{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at?->diffForHumans() }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    <x-ui.confirm :confirm="$confirm ?? null" />
</div>
