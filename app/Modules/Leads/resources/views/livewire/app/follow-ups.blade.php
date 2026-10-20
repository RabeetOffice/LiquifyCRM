{{-- Follow-ups (user request, 20 Oct 2026): a diary-like calendar (week with hours, or month) in the person's own time
     zone, and the lists. A click opens the lead in the slide-over; a follow-up can be dragged to another day or time. --}}
<div @class(['flex flex-col gap-6', 'lq-wide' => $view !== 'list']) data-live-refresh>
    <x-ui.page-header title="Follow-ups" :subtitle="'Everything you promised to do, in your own time ('.$zoneCity.'). Set them from a lead with Follow up; drag one to move it. You get a reminder 10, 5 and 1 minutes before.'">
        <x-slot:actions>
            <div class="lq-seg" role="tablist" aria-label="How to show the follow-ups">
                @foreach (['week' => ['Week', 'heroicon-o-view-columns'], 'month' => ['Month', 'heroicon-o-calendar-days'], 'list' => ['List', 'heroicon-o-list-bullet']] as $key => [$label, $icon])
                    <button type="button" @class(['lq-seg-item', 'lq-seg-active' => $view === $key]) wire:click="$set('view', '{{ $key }}')" role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}">
                        @svg($icon) {{ $label }}
                    </button>
                @endforeach
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($calendar !== null)
        <x-ui.card flush class="lq-cal-card">
            <div class="lq-cal-bar">
                <div class="lq-month lq-month-pill">
                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="go(-1)" title="{{ $view === 'month' ? 'Previous month' : 'Previous week' }}"><x-heroicon-o-chevron-left class="h-5 w-5" /></button>
                    <span class="lq-month-label lq-cal-title">{{ $calendar['title'] }}</span>
                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="go(1)" title="{{ $view === 'month' ? 'Next month' : 'Next week' }}"><x-heroicon-o-chevron-right class="h-5 w-5" /></button>
                </div>
                <x-ui.button size="sm" variant="secondary" wire:click="today">Today</x-ui.button>

                <div class="lq-cal-counts">
                    @if ($counts['overdue'] > 0)
                        <button type="button" class="lq-cal-count lq-cal-count-late" wire:click="showList('overdue')"><x-heroicon-m-exclamation-circle /> {{ $counts['overdue'] }} overdue</button>
                    @endif
                    <span class="lq-cal-count"><x-heroicon-m-calendar /> {{ $counts['today'] }} today</span>
                    <span class="lq-cal-count"><x-heroicon-m-arrow-trending-up /> {{ $counts['upcoming'] }} coming up</span>
                </div>
            </div>

            @if ($view === 'week')
                @php
                    $hour = 3.5; // rem per hour
                    $height = ($calendar['to'] - $calendar['from']) / 60 * $hour;
                @endphp
                {{-- Drag and drop: the column under the pointer gives the day, the height in it the time (to 15 minutes). --}}
                <div
                    class="lq-week"
                    style="--lq-cal-hour: {{ $hour }}rem;"
                    x-data="{
                        dragging: null,
                        over: null,
                        from: {{ $calendar['from'] }},
                        drop(event, day) {
                            const id = this.dragging;
                            this.dragging = null;
                            this.over = null;
                            if (id === null) return;
                            const box = event.currentTarget.getBoundingClientRect();
                            const minutes = this.from + Math.max(0, event.clientY - box.top) / box.height * {{ $calendar['to'] - $calendar['from'] }};
                            $wire.move(id, day, Math.round(minutes));
                        },
                    }"
                >
                    <div class="lq-week-head">
                        <span class="lq-week-corner"></span>
                        @foreach ($calendar['days'] as $day)
                            <span @class(['lq-week-day', 'lq-week-day-today' => $day['today'], 'lq-week-day-past' => $day['past']]) wire:key="head-{{ $day['date'] }}">
                                <span class="lq-week-day-name">{{ $day['label'] }}</span>
                                <span class="lq-week-day-num">{{ $day['day'] }}</span>
                            </span>
                        @endforeach
                    </div>

                    <div class="lq-week-body">
                        <div class="lq-week-hours" style="height: {{ $height }}rem">
                            @foreach ($calendar['hours'] as $h)
                                <span style="top: {{ ($h * 60 - $calendar['from']) / 60 * $hour }}rem">{{ str_pad((string) $h, 2, '0', STR_PAD_LEFT) }}:00</span>
                            @endforeach
                        </div>

                        @foreach ($calendar['days'] as $day)
                            <div
                                @class(['lq-week-col', 'lq-week-col-today' => $day['today'], 'lq-week-col-past' => $day['past']])
                                style="height: {{ $height }}rem"
                                wire:key="col-{{ $day['date'] }}"
                                x-on:dragover.prevent="over = @js($day['date'])"
                                x-on:dragleave="over === @js($day['date']) && (over = null)"
                                x-on:drop.prevent="drop($event, @js($day['date']))"
                                :class="over === @js($day['date']) && 'lq-week-col-over'"
                            >
                                @foreach ($calendar['hours'] as $h)
                                    <span class="lq-week-line" style="top: {{ ($h * 60 - $calendar['from']) / 60 * $hour }}rem"></span>
                                @endforeach

                                @if ($day['today'] && $calendar['now'] !== null)
                                    <span class="lq-week-now" style="top: {{ ($calendar['now'] - $calendar['from']) / 60 * $hour }}rem" title="Now"></span>
                                @endif

                                @foreach ($day['events'] as $event)
                                    <div
                                        @class(['lq-event', 'lq-event-late' => $event['late'], 'lq-event-done' => $event['done'], 'lq-event-open' => $event['canOpen']])
                                        style="top: {{ ($event['minutes'] - $calendar['from']) / 60 * $hour }}rem; height: {{ 0.75 * $hour }}rem; left: calc({{ $event['column'] }} * 100% / {{ $event['columns'] }} + 2px); width: calc(100% / {{ $event['columns'] }} - 4px);"
                                        wire:key="event-{{ $event['id'] }}"
                                        @if ($event['canMove']) draggable="true" x-on:dragstart="dragging = {{ $event['id'] }}; $event.dataTransfer.effectAllowed = 'move'" x-on:dragend="dragging = null; over = null" @endif
                                        @if ($event['canOpen']) x-on:click="$wire.open = {{ $event['lead']->getKey() }}; $wire.openLead({{ $event['lead']->getKey() }})" role="button" tabindex="0" @endif
                                        title="{{ $event['time'] }} · {{ $event['title'] }}{{ $event['lead'] ? ' · '.$event['lead']->name : '' }}{{ $event['owner'] ? ' · '.$event['owner'] : '' }}"
                                    >
                                        <span class="lq-event-line"><span class="lq-event-time">{{ $event['time'] }}</span> <span class="lq-event-title">{{ $event['lead']?->name ?? $event['title'] }}</span></span>
                                        @if ($event['lead'])
                                            <span class="lq-event-sub">{{ $event['title'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div
                    class="lq-monthgrid"
                    x-data="{
                        dragging: null,
                        over: null,
                        drop(day) {
                            const id = this.dragging;
                            this.dragging = null;
                            this.over = null;
                            if (id !== null) $wire.move(id, day);
                        },
                    }"
                >
                    @foreach (array_slice($calendar['days'], 0, 7) as $day)
                        <span class="lq-monthgrid-name">{{ $day['label'] }}</span>
                    @endforeach

                    @foreach ($calendar['days'] as $day)
                        <div
                            @class(['lq-monthcell', 'lq-monthcell-out' => ! $day['inMonth'], 'lq-monthcell-today' => $day['today'], 'lq-monthcell-past' => $day['past']])
                            wire:key="cell-{{ $day['date'] }}"
                            x-on:dragover.prevent="over = @js($day['date'])"
                            x-on:dragleave="over === @js($day['date']) && (over = null)"
                            x-on:drop.prevent="drop(@js($day['date']))"
                            :class="over === @js($day['date']) && 'lq-monthcell-over'"
                        >
                            <button type="button" class="lq-monthcell-num" wire:click="showWeek('{{ $day['date'] }}')" title="Show this week">{{ $day['day'] }}</button>

                            @foreach (array_slice($day['events'], 0, 3) as $event)
                                <div
                                    @class(['lq-mevent', 'lq-mevent-late' => $event['late'], 'lq-mevent-done' => $event['done'], 'lq-event-open' => $event['canOpen']])
                                    wire:key="mevent-{{ $event['id'] }}"
                                    @if ($event['canMove']) draggable="true" x-on:dragstart="dragging = {{ $event['id'] }}; $event.dataTransfer.effectAllowed = 'move'" x-on:dragend="dragging = null; over = null" @endif
                                    @if ($event['canOpen']) x-on:click="$wire.open = {{ $event['lead']->getKey() }}; $wire.openLead({{ $event['lead']->getKey() }})" role="button" tabindex="0" @endif
                                    title="{{ $event['time'] }} · {{ $event['title'] }}{{ $event['lead'] ? ' · '.$event['lead']->name : '' }}"
                                >
                                    <span class="lq-mevent-dot"></span>
                                    <span class="lq-mevent-time">{{ $event['time'] }}</span>
                                    <span class="lq-mevent-title">{{ $event['lead']?->name ?? $event['title'] }}</span>
                                </div>
                            @endforeach

                            @if (count($day['events']) > 3)
                                <button type="button" class="lq-monthcell-more" wire:click="showWeek('{{ $day['date'] }}')">{{ count($day['events']) - 3 }} more</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @else
        <x-ui.card flush class="lq-list">
            <div class="lq-viewbar">
                <div class="lq-views" role="tablist" aria-label="Follow-ups by date">
                    <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'overdue']) wire:click="$set('tab', 'overdue')" role="tab">Overdue @if ($counts['overdue'] > 0)<span class="lq-view-count lq-view-count-danger">{{ $counts['overdue'] }}</span>@endif</button>
                    <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'today']) wire:click="$set('tab', 'today')" role="tab">Today @if ($counts['today'] > 0)<span class="lq-view-count lq-view-count-primary">{{ $counts['today'] }}</span>@endif</button>
                    <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'upcoming']) wire:click="$set('tab', 'upcoming')" role="tab">Upcoming @if ($counts['upcoming'] > 0)<span class="lq-view-count">{{ $counts['upcoming'] }}</span>@endif</button>
                    <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'done']) wire:click="$set('tab', 'done')" role="tab">Done</button>
                </div>
            </div>

            @if ($tasks->isEmpty())
                <x-ui.empty :title="match ($tab) { 'overdue' => 'Nothing overdue', 'upcoming' => 'Nothing planned yet', 'done' => 'Nothing done yet', default => 'Nothing due today' }" description="Open a lead and click Follow up to plan the next step." :image="asset('images/brand/empty-followups.webp')" />
            @else
                <ul class="lq-tasks lq-tasks-list">
                    @foreach ($tasks as $task)
                        <li @class(['lq-task', 'lq-task-done' => $task->isDone()]) wire:key="task-{{ $task->getKey() }}">
                            @if ($task->isDone())
                                <button type="button" class="lq-task-check lq-task-check-done" wire:click="reopen({{ $task->getKey() }})" title="Reopen"><x-heroicon-m-check /></button>
                            @else
                                <button type="button" class="lq-task-check" wire:click="complete({{ $task->getKey() }})" title="Mark done"><x-heroicon-m-check /></button>
                            @endif

                            <span class="lq-task-main">
                                <span class="lq-task-title">{{ $task->title }}</span>
                                @if ($task->lead)
                                    <span class="lq-task-lead">
                                        <x-ui.avatar :name="$task->lead->name" size="xs" tone />
                                        @can('view', $task->lead)
                                            <button type="button" class="lq-link" x-on:click="$wire.open = {{ $task->lead->getKey() }}; $wire.openLead({{ $task->lead->getKey() }})">{{ $task->lead->name }}</button>
                                        @else
                                            <span>{{ $task->lead->name }}</span>
                                        @endcan
                                        <x-ui.status-pill :status="$task->lead->status" />
                                    </span>
                                @endif
                            </span>

                            @if ($task->assignee)
                                <span class="lq-person lq-task-owner" title="Owner"><x-ui.avatar :name="$task->assignee->name" :src="$task->assignee->avatarUrl()" size="xs" /> {{ $task->assignee->name }}</span>
                            @endif

                            <x-ui.due :at="$task->due_at" :done="$task->isDone()" />
                        </li>
                    @endforeach
                </ul>
                {{ $tasks->links('pagination.lq') }}
            @endif
        </x-ui.card>
    @endif

    @include('leads::livewire.app.partials.lead-sheet')
</div>
