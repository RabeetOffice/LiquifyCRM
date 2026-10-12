<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header title="Follow-ups" subtitle="Everything you promised to do, by date. Set them from a lead with Follow up." />

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
                                    <a href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $task->lead]) }}" class="lq-link" wire:navigate>{{ $task->lead->name }}</a>
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
</div>
