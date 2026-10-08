<div class="flex flex-col gap-6">
    <x-ui.page-header title="Follow-ups" subtitle="Everything you promised to do, by date. Set them from a lead with Follow up on." />

    <div class="lq-tabs" role="tablist">
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'overdue']) wire:click="$set('tab', 'overdue')">Overdue @if ($counts['overdue'] > 0)<span class="lq-badge lq-badge-danger">{{ $counts['overdue'] }}</span>@endif</button>
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'today']) wire:click="$set('tab', 'today')">Today @if ($counts['today'] > 0)<span class="lq-badge lq-badge-primary">{{ $counts['today'] }}</span>@endif</button>
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'upcoming']) wire:click="$set('tab', 'upcoming')">Upcoming @if ($counts['upcoming'] > 0)<span class="lq-badge">{{ $counts['upcoming'] }}</span>@endif</button>
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'done']) wire:click="$set('tab', 'done')">Done</button>
    </div>

    <x-ui.card flush>
        @if ($tasks->isEmpty())
            <x-ui.empty :title="match ($tab) { 'overdue' => 'Nothing overdue', 'upcoming' => 'Nothing planned yet', 'done' => 'Nothing done yet', default => 'Nothing due today' }" description="Open a lead and click Follow up on to plan the next step." :image="asset('images/brand/empty-followups.webp')" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr>
                            <th style="width: 3rem"></th>
                            <th>Lead</th>
                            <th>What</th>
                            <th>When</th>
                            <th>Owner</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            <tr wire:key="task-{{ $task->getKey() }}">
                                <td>
                                    @if ($task->isDone())
                                        <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="reopen({{ $task->getKey() }})" title="Reopen"><x-heroicon-o-check-circle class="h-5 w-5" style="color: var(--lq-success)" /></button>
                                    @else
                                        <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="complete({{ $task->getKey() }})" title="Mark done"><span class="block h-4 w-4 rounded-full" style="border: 2px solid var(--lq-muted)"></span></button>
                                    @endif
                                </td>
                                <td>
                                    @if ($task->lead)
                                        <a href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $task->lead]) }}" class="lq-cell-title" wire:navigate>{{ $task->lead->name }}</a>
                                        <span class="lq-cell-sub"><x-ui.status-pill :status="$task->lead->status" /></span>
                                    @else
                                        <span class="lq-muted">—</span>
                                    @endif
                                </td>
                                <td @class(['line-through lq-muted' => $task->isDone()])>{{ $task->title }}</td>
                                <td @class(['lq-num', 'text-[color:var(--lq-danger)]' => $task->isOverdue()])>{{ $task->due_at->format('j M Y, H:i') }}</td>
                                <td>{{ $task->assignee?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $tasks->links('pagination.lq') }}
        @endif
    </x-ui.card>
</div>
