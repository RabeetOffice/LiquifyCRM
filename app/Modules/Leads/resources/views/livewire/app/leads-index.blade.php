<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$agent ? 'My leads' : 'Leads'" :subtitle="$agent ? 'Leads assigned to you. Contact details stay masked until you reveal them.' : 'Every lead in '.$brand->name.'. New leads wait in the Unassigned queue.'">
        <x-slot:actions>
            @if ($can['export'])
                <x-ui.button variant="ghost" wire:click="export" icon="heroicon-o-arrow-down-tray">Export</x-ui.button>
            @endif
            @if ($can['import'])
                <x-ui.button variant="secondary" wire:click="$set('showImport', true)" icon="heroicon-o-arrow-up-tray">Import CSV</x-ui.button>
            @endif
            @if ($can['create'])
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('brand.leads.create', ['brand' => $brand->slug])">Add lead</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="lq-tabs" role="tablist">
        @foreach ($tabs as $key => $item)
            <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === $key]) wire:click="$set('tab', '{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                {{ $item['label'] }}
                @if ($item['count'] !== null)
                    <span @class(['lq-badge', 'lq-badge-'.$item['color']])>{{ $item['count'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <x-ui.card flush>
        <div class="lq-toolbar">
            <div class="relative w-full sm:w-80">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Search by name, email or phone…">
            </div>
            <div class="lq-toolbar-end">
                <x-ui.button size="sm" :variant="$hasFilters ? 'primary' : 'secondary'" wire:click="$toggle('showFilters')" icon="heroicon-o-funnel">Filters</x-ui.button>
                @if ($hasFilters)
                    <x-ui.button size="sm" variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
                @endif
            </div>
        </div>

        @if ($showFilters)
            <div class="lq-toolbar" style="padding-top: 0">
                <div class="lq-form-grid lq-form-grid-3 w-full">
                    <x-ui.field label="Status">
                        <x-ui.select wire:model.live="status" placeholder="Any status" class="lq-input-sm">
                            @foreach ($statuses as $status)
                                <option value="{{ $status->getKey() }}">{{ $status->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field label="Source">
                        <x-ui.select wire:model.live="source" placeholder="Any source" class="lq-input-sm">
                            @foreach ($sources as $source)
                                <option value="{{ $source->value }}">{{ $source->getLabel() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    @unless ($agent)
                        <x-ui.field label="Department">
                            <x-ui.select wire:model.live="department" placeholder="Any department" class="lq-input-sm">
                                @foreach ($departmentOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Owner">
                            <x-ui.select wire:model.live="owner" placeholder="Anyone" class="lq-input-sm">
                                @foreach ($agentOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Assigned">
                            <x-ui.select wire:model.live="assigned" placeholder="All" class="lq-input-sm">
                                <option value="no">Unassigned only</option>
                                <option value="yes">Assigned only</option>
                            </x-ui.select>
                        </x-ui.field>
                    @endunless
                    <x-ui.field label="Received from">
                        <x-ui.input type="date" wire:model.live="from" class="lq-input-sm" />
                    </x-ui.field>
                    <x-ui.field label="Received until">
                        <x-ui.input type="date" wire:model.live="until" class="lq-input-sm" />
                    </x-ui.field>
                </div>
            </div>
        @endif

        @if (count($selected) > 0)
            <div class="lq-bulkbar">
                <span class="font-semibold">{{ count($selected) }} selected</span>
                @unless ($agent)
                    <x-ui.button size="sm" wire:click="$set('showBulkAssign', true)" icon="heroicon-o-user-plus">Assign to</x-ui.button>
                @endunless
                @if ($can['distribute'])
                    <x-ui.button size="sm" wire:click="$set('showBulkSend', true)" icon="heroicon-o-arrow-right-circle">Send to department</x-ui.button>
                @endif
                <x-ui.button size="sm" wire:click="$set('showBulkStatus', true)" icon="heroicon-o-arrow-path">Change status</x-ui.button>
                @if ($can['deleteAny'])
                    <x-ui.button size="sm" variant="danger" wire:click="confirmBulkDelete" icon="heroicon-o-trash">Delete</x-ui.button>
                @endif
                <x-ui.button size="sm" variant="ghost" wire:click="$set('selected', [])">Clear</x-ui.button>
            </div>
        @endif

        @if ($leads->isEmpty())
            <x-ui.empty :title="$search !== '' || $hasFilters ? 'No leads match' : ($agent ? 'No leads assigned to you yet' : 'No leads yet')" :description="$search !== '' || $hasFilters ? 'Try another search or clear the filters.' : ($agent ? 'Your Manager assigns leads to you; they appear here with a notification.' : 'Add a lead by hand or import a CSV. Website and Facebook sources arrive in weeks 3 and 4.')" :image="$search === '' && ! $hasFilters ? asset('images/brand/empty-leads.webp') : null" :icon="$search !== '' || $hasFilters ? 'heroicon-o-magnifying-glass' : null">
                <x-slot:actions>
                    @if ($search !== '' || $hasFilters)
                        <x-ui.button variant="secondary" wire:click="clearFilters">Clear search and filters</x-ui.button>
                    @elseif ($can['create'])
                        <x-ui.button variant="primary" :href="route('brand.leads.create', ['brand' => $brand->slug])">Add your first lead</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty>
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr>
                            <th style="width: 2.5rem">
                                <input type="checkbox" class="h-4 w-4" style="accent-color: var(--lq-orange)" x-data x-on:change="$wire.set('selected', $event.target.checked ? @js($leads->pluck('id')->all()) : [])" @checked(count($selected) > 0 && count($selected) === $leads->count())>
                            </th>
                            <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Lead" />
                            <x-ui.sort-th field="status_id" :sort="$sort" :direction="$direction" label="Status" />
                            <th>Source</th>
                            @unless ($agent)
                                <th>Department</th>
                                <th>Owner</th>
                            @endunless
                            <x-ui.sort-th field="next_follow_up_at" :sort="$sort" :direction="$direction" label="Follow up" />
                            <x-ui.sort-th field="created_at" :sort="$sort" :direction="$direction" label="Received" />
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leads as $lead)
                            <tr wire:key="lead-{{ $lead->getKey() }}" @class(['lq-row-click', 'lq-selected' => in_array($lead->getKey(), $selected, true)])>
                                <td x-on:click.stop><input type="checkbox" class="h-4 w-4" style="accent-color: var(--lq-orange)" value="{{ $lead->getKey() }}" wire:model.live="selected"></td>
                                <td wire:click="openLead({{ $lead->getKey() }})">
                                    <span class="lq-cell-title">{{ $lead->name }}</span>
                                    <span class="lq-cell-sub">{{ ($fullContact[$lead->getKey()] ?? false) ? ($lead->email ?? $lead->phone) : ($lead->maskedEmail() ?? $lead->maskedPhone()) }}</span>
                                </td>
                                <td wire:click="openLead({{ $lead->getKey() }})"><x-ui.status-pill :status="$lead->status" /></td>
                                <td wire:click="openLead({{ $lead->getKey() }})"><x-ui.lead-source :lead="$lead" /></td>
                                @unless ($agent)
                                    <td wire:click="openLead({{ $lead->getKey() }})">{{ $lead->department?->name ?? '—' }}</td>
                                    <td wire:click="openLead({{ $lead->getKey() }})">
                                        @if ($lead->assignee)
                                            <span class="flex items-center gap-2"><x-ui.avatar :name="$lead->assignee->name" :src="$lead->assignee->avatarUrl()" size="sm" /> {{ $lead->assignee->name }}</span>
                                        @else
                                            <x-ui.badge color="primary">Unassigned</x-ui.badge>
                                        @endif
                                    </td>
                                @endunless
                                <td wire:click="openLead({{ $lead->getKey() }})" @class(['lq-num', 'text-[color:var(--lq-danger)]' => $lead->next_follow_up_at?->isPast()])>{{ $lead->next_follow_up_at?->format('j M, H:i') ?? '—' }}</td>
                                <td wire:click="openLead({{ $lead->getKey() }})" class="lq-muted" title="{{ $lead->created_at?->format('j M Y, H:i') }}">{{ $lead->created_at?->diffForHumans(short: true) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $leads->links('pagination.lq') }}
        @endif
    </x-ui.card>

    {{-- Lead slide-over --}}
    @if ($open)
        <div class="lq-sheet-backdrop" x-data x-on:keydown.escape.window="$wire.closeLead()" x-on:click.self="$wire.closeLead()">
            <div class="lq-sheet" role="dialog" aria-modal="true">
                <header class="lq-modal-header">
                    <div class="min-w-0">
                        <h2 class="lq-modal-title">{{ \App\Modules\Leads\Models\Lead::query()->find($open)?->name ?? 'Lead' }}</h2>
                        <a href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $open]) }}" class="lq-link text-xs" wire:navigate>Open full page</a>
                    </div>
                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="closeLead" title="Close"><x-heroicon-o-x-mark class="h-5 w-5" /></button>
                </header>
                <div class="lq-modal-body">
                    @livewire(\App\Modules\Leads\Livewire\App\LeadPanel::class, ['leadId' => $open], key('lead-panel-'.$open))
                </div>
            </div>
        </div>
    @endif

    {{-- Bulk modals --}}
    <x-ui.modal model="showBulkAssign" :title="'Assign '.count($selected).' '.str('lead')->plural(count($selected))" size="sm">
        <form wire:submit="bulkAssign" class="lq-form" id="bulk-assign-form">
            <x-ui.field label="Agent" name="bulkAssignTo" required>
                <x-ui.select wire:model="bulkAssignTo" name="bulkAssignTo" placeholder="Choose an Agent…">
                    @foreach ($agentOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showBulkAssign', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="bulk-assign-form" variant="primary">Assign</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal model="showBulkSend" :title="'Send '.count($selected).' '.str('lead')->plural(count($selected)).' to a department'" size="sm">
        <form wire:submit="bulkSend" class="lq-form" id="bulk-send-form">
            <x-ui.field label="Department" name="bulkSendTo" required>
                <x-ui.select wire:model="bulkSendTo" name="bulkSendTo" placeholder="Choose a department…">
                    @foreach ($departmentOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showBulkSend', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="bulk-send-form" variant="primary">Send</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.modal model="showBulkStatus" :title="'Change status of '.count($selected).' '.str('lead')->plural(count($selected))" size="sm">
        <form wire:submit="bulkStatus" class="lq-form" id="bulk-status-form">
            <x-ui.field label="Status" name="bulkStatusTo" required>
                <x-ui.select wire:model="bulkStatusTo" name="bulkStatusTo" placeholder="Choose a status…">
                    @foreach ($statuses as $status)
                        <option value="{{ $status->getKey() }}">{{ $status->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showBulkStatus', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="bulk-status-form" variant="primary">Save</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    @if ($can['import'])
        <x-ui.modal model="showImport" title="Import leads from a CSV or Excel file" description="Original dates are kept, duplicates inside this brand are merged by email or phone, and every row is tagged Imported." size="lg">
            @livewire(\App\Modules\Leads\Livewire\App\LeadImport::class, ['brand' => $brand], key('lead-import'))
        </x-ui.modal>
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
