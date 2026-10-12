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

    // One action is the obvious next step and is the only orange one.
    $next = match (true) {
        $can['assign'] && ! $lead->assigned_to => 'assign',
        $can['recordSale'] && ! $lead->status->is_won => 'won',
        default => null,
    };
@endphp
<div
    @class(['lq-lead', 'lq-lead-page' => $page])
    x-data
    data-live-refresh
    x-on:keydown.window="
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) || $event.metaKey || $event.ctrlKey || $event.altKey) return;
        if ($event.key === 'a' && @js($can['assign'])) $wire.openAssign();
        if ($event.key === 's' && @js($can['update'])) $wire.openStatus();
        if ($event.key === 'n' && @js($can['update'])) $wire.openNote();
        if ($event.key === 'f' && @js($can['update'])) $wire.openFollowUp();
    "
>
    {{-- Who this is --}}
    <header class="lq-lead-hero">
        <x-ui.avatar :name="$lead->name" size="lg" tone />
        <div class="min-w-0 flex-1">
            <h2 class="lq-lead-name">{{ $lead->name }}</h2>
            <div class="lq-lead-meta">
                <x-ui.lead-source :lead="$lead" />
                <span title="{{ $lead->created_at?->format('j M Y, H:i') }}">Received {{ $lead->created_at?->diffForHumans() }}</span>
                @if ($lead->last_activity_at && $lead->last_activity_at->gt($lead->created_at))
                    <span title="{{ $lead->last_activity_at->format('j M Y, H:i') }}">· Last activity {{ $lead->last_activity_at->diffForHumans() }}</span>
                @endif
            </div>
        </div>
    </header>

    {{-- Where it stands: one click on a step moves the lead there. --}}
    <nav class="lq-path" aria-label="Pipeline step" x-data x-init="$nextTick(() => { const step = $el.querySelector('[aria-current]'); if (step) $el.scrollLeft = step.offsetLeft - ($el.clientWidth - step.offsetWidth) / 2 })">
        @foreach ($path as $step)
            <button
                type="button"
                @class(['lq-path-step', 'lq-path-'.$step['state']])
                style="--pill-color: {{ $step['status']->color }}"
                @if ($step['state'] === 'current') aria-current="step" @endif
                @if ($can['update'] && $step['state'] !== 'current')
                    wire:click="moveTo({{ $step['status']->getKey() }})"
                    title="Move to {{ $step['status']->name }}"
                @else
                    disabled
                @endif
            >
                @if ($step['state'] === 'done')
                    <x-heroicon-m-check />
                @endif
                <span>{{ $step['status']->name }}</span>
            </button>
        @endforeach
    </nav>

    {{-- What you can do --}}
    @if ($can['assign'] || $can['update'] || $can['distribute'] || $can['recordSale'] || $can['delete'])
        <div class="lq-actions">
            @if ($can['assign'])
                <button type="button" @class(['lq-action', 'lq-action-primary' => $next === 'assign']) wire:click="openAssign">
                    <span class="lq-action-icon"><x-heroicon-o-user-plus /></span>
                    <span class="lq-action-label">{{ $lead->assigned_to ? 'Reassign' : 'Assign' }}</span>
                    <kbd class="lq-kbd">A</kbd>
                </button>
            @endif
            @if ($can['update'])
                <button type="button" class="lq-action" wire:click="openNote">
                    <span class="lq-action-icon"><x-heroicon-o-pencil-square /></span>
                    <span class="lq-action-label">Add note</span>
                    <kbd class="lq-kbd">N</kbd>
                </button>
                <button type="button" class="lq-action" wire:click="openFollowUp">
                    <span class="lq-action-icon"><x-heroicon-o-calendar-days /></span>
                    <span class="lq-action-label">Follow up</span>
                    <kbd class="lq-kbd">F</kbd>
                </button>
            @endif
            @if ($can['distribute'])
                <button type="button" class="lq-action" wire:click="openSend">
                    <span class="lq-action-icon"><x-heroicon-o-arrow-right-circle /></span>
                    <span class="lq-action-label">Send to team</span>
                </button>
            @endif
            @if ($can['recordSale'])
                <button type="button" @class(['lq-action', 'lq-action-primary' => $next === 'won']) wire:click="openWon">
                    <span class="lq-action-icon"><x-heroicon-o-trophy /></span>
                    <span class="lq-action-label">{{ $lead->status->is_won ? 'Add another sale' : 'Mark as Won' }}</span>
                </button>
            @endif
            @if ($can['delete'])
                <button type="button" class="lq-action lq-action-danger" wire:click="confirmDelete">
                    <span class="lq-action-icon"><x-heroicon-o-trash /></span>
                    <span class="lq-action-label">Delete</span>
                </button>
            @endif
        </div>
    @endif

    {{-- Contact, details and sales --}}
    <aside class="lq-lead-side">
        <section class="lq-lead-section">
            <h3 class="lq-lead-heading">Contact</h3>
            <ul class="lq-facts">
                <li class="lq-fact">
                    <span class="lq-fact-icon"><x-heroicon-o-phone /></span>
                    <span class="lq-fact-text">
                        <span class="lq-fact-label">Phone</span>
                        @if (! $lead->phone)
                            <span class="lq-fact-value lq-muted">No phone</span>
                        @elseif ($canSeeFull)
                            <a href="tel:{{ $lead->phone }}" class="lq-fact-value lq-tabular hover:underline">{{ $lead->phone }}</a>
                        @else
                            <span class="lq-fact-value lq-tabular">{{ $lead->maskedPhone() }}</span>
                        @endif
                    </span>
                    @if ($lead->phone && $canSeeFull)
                        <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-data="copy(@js($lead->phone))" x-on:click="copy()" x-bind:title="copied ? 'Copied' : 'Copy phone'">
                            <x-heroicon-o-clipboard x-show="! copied" />
                            <x-heroicon-o-check x-show="copied" x-cloak />
                        </button>
                    @endif
                </li>
                <li class="lq-fact">
                    <span class="lq-fact-icon"><x-heroicon-o-envelope /></span>
                    <span class="lq-fact-text">
                        <span class="lq-fact-label">Email</span>
                        @if (! $lead->email)
                            <span class="lq-fact-value lq-muted">No email</span>
                        @elseif ($canSeeFull)
                            <a href="mailto:{{ $lead->email }}" class="lq-fact-value hover:underline">{{ $lead->email }}</a>
                        @else
                            <span class="lq-fact-value">{{ $lead->maskedEmail() }}</span>
                        @endif
                    </span>
                    @if ($lead->email && $canSeeFull)
                        <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-data="copy(@js($lead->email))" x-on:click="copy()" x-bind:title="copied ? 'Copied' : 'Copy email'">
                            <x-heroicon-o-clipboard x-show="! copied" />
                            <x-heroicon-o-check x-show="copied" x-cloak />
                        </button>
                    @endif
                </li>
            </ul>

            @if ($can['reveal'])
                <div class="lq-reveal">
                    <p>Contact details stay masked until you reveal them. Every reveal is logged, and your Manager sets how many you can do per hour.</p>
                    <x-ui.button size="sm" wire:click="reveal" icon="heroicon-o-eye">Reveal contact details</x-ui.button>
                </div>
            @endif
        </section>

        <section class="lq-lead-section">
            <h3 class="lq-lead-heading lq-lead-heading-row">
                Details
                @if ($can['update'])
                    <button type="button" class="lq-link text-xs" wire:click="openDetails">Edit details</button>
                @endif
            </h3>
            <dl class="lq-kv">
                <div>
                    <dt>Owner</dt>
                    <dd>
                        @if ($lead->assignee)
                            <span class="lq-person"><x-ui.avatar :name="$lead->assignee->name" :src="$lead->assignee->avatarUrl()" size="xs" /> {{ $lead->assignee->name }}</span>
                        @else
                            <span class="lq-unassigned">Unassigned</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt>Department</dt>
                    <dd>{{ $lead->department?->name ?? 'Not sent to a department yet' }}</dd>
                </div>
                <div>
                    <dt>Next follow-up</dt>
                    <dd><x-ui.due :at="$lead->next_follow_up_at" empty="None set" /></dd>
                </div>
                <div>
                    <dt>Service</dt>
                    <dd>{{ $lead->fields['service'] ?? '—' }}</dd>
                </div>
                @if (filled($lead->fields['company'] ?? null))
                    <div>
                        <dt>Company</dt>
                        <dd>{{ $lead->fields['company'] }}</dd>
                    </div>
                @endif
                @if (filled($lead->fields['address'] ?? null))
                    <div>
                        <dt>Address</dt>
                        <dd class="whitespace-pre-line">{{ $lead->fields['address'] }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        @if ($sales->isNotEmpty())
            <section class="lq-lead-section lq-lead-sales">
                <h3 class="lq-lead-heading"><x-heroicon-o-trophy /> {{ $sales->count() === 1 ? 'Sale' : 'Sales' }}</h3>
                <ul class="lq-sales">
                    @foreach ($sales as $sale)
                        <li>
                            <span class="lq-sales-amount">{{ \App\Modules\Sales\Support\Money::format($sale->amount, $sale->currency) }}</span>
                            <span class="lq-sales-meta">{{ $sale->service ? $sale->service.' · ' : '' }}{{ $sale->closed_at->format('j M Y') }}{{ $sale->closer ? ' · '.$sale->closer->name : '' }}</span>
                        </li>
                    @endforeach
                </ul>
                @if (auth()->user()?->isGlobalAdmin() && $lead->client_id)
                    <a class="lq-link text-sm" href="{{ route('brand.clients.show', ['brand' => $lead->brand_id ? \App\Modules\Brands\Models\Brand::query()->whereKey($lead->brand_id)->value('slug') : '', 'client' => $lead->client_id]) }}" wire:navigate>Correct or remove a sale</a>
                @endif
            </section>
        @endif
    </aside>

    {{-- History and conversation --}}
    <div class="lq-lead-main">
        <div class="lq-views" role="tablist">
            <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'timeline']) wire:click="$set('tab', 'timeline')" role="tab"><x-heroicon-o-clock /> Timeline</button>
            <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'message']) wire:click="$set('tab', 'message')" role="tab"><x-heroicon-o-chat-bubble-bottom-center-text /> Message</button>
            <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'notes']) wire:click="$set('tab', 'notes')" role="tab"><x-heroicon-o-pencil-square /> Notes @if ($lead->notes->isNotEmpty())<span class="lq-view-count">{{ $lead->notes->count() }}</span>@endif</button>
            <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'tasks']) wire:click="$set('tab', 'tasks')" role="tab"><x-heroicon-o-calendar-days /> Follow-ups @if ($openTasks->isNotEmpty())<span class="lq-view-count lq-view-count-primary">{{ $openTasks->count() }}</span>@endif</button>
            @if ($conversationId)
                <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'chat']) wire:click="$set('tab', 'chat')" role="tab"><x-heroicon-o-chat-bubble-left-right /> Messenger</button>
            @endif
        </div>

        <div class="lq-lead-tab">
            @if ($tab === 'chat' && $conversationId)
                <div class="lq-thread-embed">
                    @livewire(\App\Modules\Meta\Livewire\App\ConversationThread::class, ['conversationId' => $conversationId], key('lead-thread-'.$conversationId))
                </div>
            @elseif ($tab === 'timeline')
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
                                        <div class="lq-timeline-quote">{{ $entry->properties['excerpt'] }}</div>
                                    @elseif ($entry->event === 'leads.repeat_enquiry' && ! empty($entry->properties['message']))
                                        <div class="lq-timeline-quote">{{ str($entry->properties['message'])->limit(200) }}</div>
                                    @endif
                                    <div class="lq-timeline-meta">{{ $entry->causer?->name ?? 'System' }} · <span title="{{ $entry->created_at?->format('j M Y, H:i') }}">{{ $entry->created_at?->diffForHumans() }}</span></div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            @elseif ($tab === 'message')
                <div class="flex flex-col gap-5">
                    @if ($lead->message)
                        <blockquote class="lq-quote">{{ $lead->message }}</blockquote>
                    @else
                        <p class="lq-help">This lead came without a message.</p>
                    @endif
                    @if (filled($lead->fields))
                        <div>
                            <p class="lq-lead-heading">Extra fields</p>
                            <dl class="lq-dl">
                                @foreach ($lead->fields as $key => $value)
                                    <div><dt>{{ str($key)->headline() }}</dt><dd>{{ is_array($value) ? json_encode($value) : $value }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @endif
                    @if (filled($lead->utm))
                        <div>
                            <p class="lq-lead-heading">Campaign</p>
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
                            <x-slot:actions><x-ui.button size="sm" wire:click="openNote" icon="heroicon-o-plus">Add a note</x-ui.button></x-slot:actions>
                        @endif
                    </x-ui.empty>
                @else
                    <div class="flex flex-col gap-3">
                        @foreach ($lead->notes as $noteItem)
                            <article class="lq-note" wire:key="note-{{ $noteItem->getKey() }}">
                                <header class="lq-note-head">
                                    <x-ui.avatar :name="$noteItem->author?->name ?? '?'" :src="$noteItem->author?->avatarUrl()" size="xs" />
                                    <span class="lq-note-author">{{ $noteItem->author?->name ?? 'Unknown' }}</span>
                                    <span title="{{ $noteItem->created_at?->format('j M Y, H:i') }}">{{ $noteItem->created_at?->diffForHumans() }}</span>
                                </header>
                                <p class="lq-note-body">{{ $noteItem->body }}</p>
                            </article>
                        @endforeach
                    </div>
                @endif
            @elseif ($tab === 'tasks')
                @if ($lead->tasks->isEmpty())
                    <x-ui.empty title="No follow-ups yet" description="Plan the next step so this lead does not go quiet." icon="heroicon-o-calendar-days">
                        @if ($can['update'])
                            <x-slot:actions><x-ui.button size="sm" wire:click="openFollowUp" icon="heroicon-o-plus">Set a follow-up</x-ui.button></x-slot:actions>
                        @endif
                    </x-ui.empty>
                @else
                    <ul class="lq-tasks">
                        @foreach ($lead->tasks->sortByDesc(fn ($task) => [$task->done_at === null, $task->due_at]) as $task)
                            <li @class(['lq-task', 'lq-task-done' => $task->isDone()]) wire:key="task-{{ $task->getKey() }}">
                                @if ($task->isDone())
                                    <span class="lq-task-check lq-task-check-done"><x-heroicon-m-check /></span>
                                @elseif ($can['update'])
                                    <button type="button" class="lq-task-check" wire:click="completeTask({{ $task->getKey() }})" title="Mark done"><x-heroicon-m-check /></button>
                                @else
                                    <span class="lq-task-check"></span>
                                @endif
                                <span class="lq-task-title">{{ $task->title }}</span>
                                <x-ui.due :at="$task->due_at" :done="$task->isDone()" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    </div>

    {{-- Modals --}}
    @include('leads::livewire.app.partials.lead-details')

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

    <x-ui.modal model="showWon" title="Mark as Won" description="Record the sale. The person becomes a client of this brand and the sale counts towards this month's targets." size="sm">
        <form wire:submit="recordSale" class="lq-form" id="won-form">
            <x-ui.field label="Amount" name="wonAmount" required>
                <div class="lq-input-group">
                    <span class="lq-input-prefix">{{ $brandCurrency }}</span>
                    <x-ui.input type="number" step="0.01" min="0" wire:model="wonAmount" name="wonAmount" inputmode="decimal" placeholder="1500" />
                </div>
            </x-ui.field>
            <x-ui.field label="Service or package" name="wonService">
                <x-ui.input wire:model="wonService" name="wonService" placeholder="Publishing package" />
            </x-ui.field>
            <x-ui.field label="Close date" name="wonClosedAt" required>
                <x-ui.input type="date" wire:model="wonClosedAt" name="wonClosedAt" max="{{ now()->toDateString() }}" />
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showWon', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="won-form" variant="primary" wire:loading.attr="disabled" wire:target="recordSale">Record sale</x-ui.button>
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
