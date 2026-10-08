@php
    $icons = [
        'leads.created' => 'heroicon-o-inbox-arrow-down',
        'leads.repeat_enquiry' => 'heroicon-o-arrow-path-rounded-square',
        'leads.assigned' => 'heroicon-o-user-plus',
        'leads.unassigned' => 'heroicon-o-user-minus',
        'leads.sent_to_department' => 'heroicon-o-arrow-right-circle',
        'leads.status_changed' => 'heroicon-o-arrow-path',
        'leads.note_added' => 'heroicon-o-pencil-square',
        'leads.contact_revealed' => 'heroicon-o-eye',
        'leads.contact_viewed' => 'heroicon-o-eye',
        'leads.follow_up_scheduled' => 'heroicon-o-calendar-days',
        'leads.follow_up_done' => 'heroicon-o-check-circle',
        'leads.imported' => 'heroicon-o-arrow-up-tray',
        'leads.deleted' => 'heroicon-o-trash',
    ];
@endphp
<div
    class="flex flex-col gap-5"
    x-data
    x-on:keydown.window="
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.metaKey || $event.ctrlKey || $event.altKey) return;
        if ($event.key === 'a' && @js($can['assign'])) $wire.openAssign();
        if ($event.key === 's' && @js($can['update'])) $wire.openStatus();
        if ($event.key === 'n' && @js($can['update'])) $wire.openNote();
        if ($event.key === 'f' && @js($can['update'])) $wire.openFollowUp();
    "
>
    {{-- Essentials --}}
    <div class="flex flex-wrap items-center gap-2">
        <x-ui.status-pill :status="$lead->status" />
        <x-ui.badge :color="$lead->source_type->getColor()" :icon="$lead->source_type->getIcon()">{{ $lead->source_type->getLabel() }}</x-ui.badge>
        <span class="lq-muted text-sm" title="{{ $lead->created_at?->format('j M Y, H:i') }}">Received {{ $lead->created_at?->diffForHumans() }}</span>
    </div>

    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
        <div>
            <dt class="lq-help">Phone</dt>
            <dd class="flex items-center gap-2 text-sm font-medium">
                <x-heroicon-o-phone class="h-4 w-4 lq-muted" />
                @if ($lead->phone)
                    @if ($canSeeFull)
                        <a href="tel:{{ $lead->phone }}" class="hover:underline">{{ $lead->phone }}</a>
                    @else
                        <span class="lq-tabular">{{ $lead->maskedPhone() }}</span>
                    @endif
                @else
                    <span class="lq-muted">No phone</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="lq-help">Email</dt>
            <dd class="flex items-center gap-2 text-sm font-medium">
                <x-heroicon-o-envelope class="h-4 w-4 lq-muted" />
                @if ($lead->email)
                    @if ($canSeeFull)
                        <a href="mailto:{{ $lead->email }}" class="hover:underline">{{ $lead->email }}</a>
                    @else
                        <span>{{ $lead->maskedEmail() }}</span>
                    @endif
                @else
                    <span class="lq-muted">No email</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="lq-help">Owner</dt>
            <dd class="text-sm font-medium">{{ $lead->assignee?->name ?? 'Unassigned' }}</dd>
        </div>
        <div>
            <dt class="lq-help">Department</dt>
            <dd class="text-sm font-medium">{{ $lead->department?->name ?? 'Not sent to a department yet' }}</dd>
        </div>
        <div>
            <dt class="lq-help">Next follow-up</dt>
            <dd @class(['text-sm font-medium', 'text-[color:var(--lq-danger)]' => $lead->next_follow_up_at?->isPast()])>{{ $lead->next_follow_up_at?->format('j M, H:i') ?? 'None set' }}</dd>
        </div>
        <div>
            <dt class="lq-help">Service</dt>
            <dd class="text-sm font-medium">{{ $lead->fields['service'] ?? '—' }}</dd>
        </div>
    </dl>

    @if ($can['reveal'])
        <div>
            <x-ui.button size="sm" wire:click="reveal" icon="heroicon-o-eye">Reveal contact details</x-ui.button>
            <p class="lq-help mt-1">Every reveal is logged. Your Manager sets how many you can do per hour.</p>
        </div>
    @endif

    {{-- Actions --}}
    <div class="flex flex-wrap gap-2">
        @if ($can['assign'])
            <x-ui.button size="sm" variant="primary" wire:click="openAssign" icon="heroicon-o-user-plus" title="Shortcut: A">{{ $lead->assigned_to ? 'Reassign' : 'Assign to' }}</x-ui.button>
        @endif
        @if ($can['distribute'])
            <x-ui.button size="sm" wire:click="openSend" icon="heroicon-o-arrow-right-circle">Send to department</x-ui.button>
        @endif
        @if ($can['update'])
            <x-ui.button size="sm" wire:click="openStatus" icon="heroicon-o-arrow-path" title="Shortcut: S">Change status</x-ui.button>
            <x-ui.button size="sm" wire:click="openNote" icon="heroicon-o-pencil-square" title="Shortcut: N">Add note</x-ui.button>
            <x-ui.button size="sm" wire:click="openFollowUp" icon="heroicon-o-calendar-days" title="Shortcut: F">Follow up on</x-ui.button>
        @endif
        @if ($can['delete'])
            <x-ui.button size="sm" variant="ghost" wire:click="confirmDelete" icon="heroicon-o-trash">Delete</x-ui.button>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="lq-tabs" role="tablist">
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'timeline']) wire:click="$set('tab', 'timeline')" role="tab"><x-heroicon-o-clock class="h-4 w-4" /> Timeline</button>
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'message']) wire:click="$set('tab', 'message')" role="tab"><x-heroicon-o-chat-bubble-bottom-center-text class="h-4 w-4" /> Message</button>
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'notes']) wire:click="$set('tab', 'notes')" role="tab"><x-heroicon-o-pencil-square class="h-4 w-4" /> Notes @if ($lead->notes->isNotEmpty())<span class="lq-count" style="background: var(--lq-raised); color: inherit">{{ $lead->notes->count() }}</span>@endif</button>
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'tasks']) wire:click="$set('tab', 'tasks')" role="tab"><x-heroicon-o-calendar-days class="h-4 w-4" /> Follow-ups @if ($openTasks->isNotEmpty())<span class="lq-count">{{ $openTasks->count() }}</span>@endif</button>
    </div>

    @if ($tab === 'timeline')
        @if ($timeline->isEmpty())
            <p class="lq-help">Nothing on the timeline yet.</p>
        @else
            <ol class="lq-timeline">
                @foreach ($timeline as $entry)
                    <li class="lq-timeline-item" wire:key="activity-{{ $entry->getKey() }}">
                        <span @class(['lq-timeline-dot', 'lq-timeline-dot-primary' => in_array($entry->event, ['leads.created', 'leads.assigned', 'leads.status_changed'], true)])>
                            @svg($icons[$entry->event] ?? 'heroicon-o-clock')
                        </span>
                        <div class="lq-timeline-body">
                            <div class="lq-timeline-title">{{ $entry->description }}</div>
                            @if (($entry->event === 'leads.note_added') && ! empty($entry->properties['excerpt']))
                                <div class="text-sm lq-muted">{{ $entry->properties['excerpt'] }}</div>
                            @elseif ($entry->event === 'leads.repeat_enquiry' && ! empty($entry->properties['message']))
                                <div class="text-sm lq-muted">{{ str($entry->properties['message'])->limit(200) }}</div>
                            @endif
                            <div class="lq-timeline-meta">{{ $entry->causer?->name ?? 'System' }} · <span title="{{ $entry->created_at?->format('j M Y, H:i') }}">{{ $entry->created_at?->diffForHumans() }}</span></div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    @elseif ($tab === 'message')
        <div class="flex flex-col gap-4">
            @if ($lead->message)
                <p class="whitespace-pre-wrap text-sm">{{ $lead->message }}</p>
            @else
                <p class="lq-help">No message.</p>
            @endif
            @if (filled($lead->fields))
                <div>
                    <p class="lq-label mb-1">Extra fields</p>
                    <dl class="lq-dl">
                        @foreach ($lead->fields as $key => $value)
                            <div><dt>{{ str($key)->headline() }}</dt><dd>{{ is_array($value) ? json_encode($value) : $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endif
            @if (filled($lead->utm))
                <div>
                    <p class="lq-label mb-1">Campaign</p>
                    <dl class="lq-dl">
                        @foreach ($lead->utm as $key => $value)
                            <div><dt>{{ $key }}</dt><dd>{{ is_array($value) ? json_encode($value) : $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    @elseif ($tab === 'notes')
        @if ($lead->notes->isEmpty())
            <x-ui.empty title="No notes yet" description="Notes are private to your team and appear on the timeline." icon="heroicon-o-pencil-square">
                @if ($can['update'])
                    <x-slot:actions><x-ui.button size="sm" variant="primary" wire:click="openNote">Add a note</x-ui.button></x-slot:actions>
                @endif
            </x-ui.empty>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($lead->notes as $noteItem)
                    <div wire:key="note-{{ $noteItem->getKey() }}">
                        <div class="lq-note">{{ $noteItem->body }}</div>
                        <div class="lq-timeline-meta mt-1 px-1">{{ $noteItem->author?->name ?? 'Unknown' }} · {{ $noteItem->created_at?->diffForHumans() }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    @elseif ($tab === 'tasks')
        @if ($lead->tasks->isEmpty())
            <p class="lq-help">No follow-ups yet.</p>
        @else
            <ul class="flex flex-col gap-2">
                @foreach ($lead->tasks->sortByDesc(fn ($task) => [$task->done_at === null, $task->due_at]) as $task)
                    <li class="flex items-center gap-3 rounded-xl px-3 py-2" style="background: var(--lq-raised)" wire:key="task-{{ $task->getKey() }}">
                        @if ($task->isDone())
                            <x-heroicon-o-check-circle class="h-5 w-5" style="color: var(--lq-success)" />
                        @elseif ($can['update'])
                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" style="width: 1.75rem; min-height: 1.75rem" wire:click="completeTask({{ $task->getKey() }})" title="Mark done"><x-heroicon-o-circle-stack class="hidden" /><span class="block h-4 w-4 rounded-full" style="border: 2px solid var(--lq-muted)"></span></button>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div @class(['text-sm font-medium', 'line-through lq-muted' => $task->isDone()])>{{ $task->title }}</div>
                            <div @class(['text-xs', 'lq-muted' => ! $task->isOverdue(), 'text-[color:var(--lq-danger)]' => $task->isOverdue()])>{{ $task->due_at->format('j M Y, H:i') }}{{ $task->isOverdue() ? ' · overdue' : '' }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    {{-- Modals --}}
    <x-ui.modal model="showAssign" :title="$lead->assigned_to ? 'Reassign lead' : 'Assign lead'" size="sm">
        <form wire:submit="assign" class="lq-form" id="assign-form">
            <x-ui.field label="Agent" name="assignTo" required>
                <x-ui.select wire:model="assignTo" name="assignTo" placeholder="Choose an Agent…" autofocus>
                    @foreach ($agentOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showAssign', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="assign-form" variant="primary">Assign</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal model="showSend" title="Send to department" size="sm">
        <form wire:submit="sendToDepartment" class="lq-form" id="send-form">
            <x-ui.field label="Department" name="sendTo" required>
                <x-ui.select wire:model="sendTo" name="sendTo" placeholder="Choose a department…">
                    @foreach ($departmentOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showSend', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="send-form" variant="primary">Send</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal model="showStatus" title="Change status" size="sm">
        <form wire:submit="changeStatus" class="lq-form" id="status-form">
            <x-ui.field label="Status" name="statusTo" required>
                <x-ui.select wire:model="statusTo" name="statusTo">
                    @foreach ($statuses as $status)
                        <option value="{{ $status->getKey() }}">{{ $status->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showStatus', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="status-form" variant="primary">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal model="showNote" title="Add a note">
        <form wire:submit="addNote" class="lq-form" id="note-form">
            <x-ui.field name="note">
                <x-ui.textarea wire:model="note" name="note" rows="5" placeholder="What happened, what is next…" autofocus />
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showNote', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="note-form" variant="primary">Add note</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal model="showFollowUp" title="Follow up on" size="sm">
        <form wire:submit="scheduleFollowUp" class="lq-form" id="followup-form">
            <x-ui.field label="When" name="followUpAt" required>
                <x-ui.input type="datetime-local" wire:model="followUpAt" name="followUpAt" step="900" />
            </x-ui.field>
            <x-ui.field label="What" name="followUpTitle" required>
                <x-ui.input wire:model="followUpTitle" name="followUpTitle" />
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showFollowUp', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="followup-form" variant="primary">Set follow-up</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.confirm :confirm="$confirm" />
</div>
