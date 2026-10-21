<div class="flex flex-col gap-6" wire:poll.60s.visible data-live-refresh
    x-data="assignMenu({ people: @js($people), teams: @js($teamChoices), assign: (lead, person) => $wire.quickAssign(lead, person), send: (lead, team) => $wire.quickSend(lead, team) })">
    @php
        $subtitle = match (true) {
            $team => 'Your team\'s leads in '.$brand->name.'. Take a free one and it is yours: its phone and email open when you do.',
            $agent => 'Leads assigned to you. Contact details stay masked until you reveal them.',
            $isAdmin => 'Every lead in '.$brand->name.'. New leads wait in the Unassigned queue.',
            default => 'The leads of your departments in '.$brand->name.', and leads given to you.',
        };
        $emptyText = match (true) {
            $search !== '' || $hasFilters => 'Try another search or clear the filters.',
            $archivedView => 'A lead that leads nowhere goes here with its Archive button. Nothing is deleted, and it comes back by itself if the person gets in touch again.',
            $team => 'New leads of your team show here the moment they arrive, with a notification. Take one and it is yours.',
            $agent => 'Your Manager assigns leads to you; they appear here with a notification.',
            $isAdmin => 'Add a lead by hand, import a CSV, or connect a website or Facebook so they arrive by themselves.',
            default => 'Leads sent to your departments appear here. You can also add one by hand.',
        };
    @endphp
    <x-ui.page-header :title="$agent && ! $team ? 'My leads' : 'Leads'" :subtitle="$subtitle">
        <x-slot:actions>
            @if ($can['archiveAny'] && ! $archivedView)
                <x-ui.button variant="ghost" icon="heroicon-o-archive-box" wire:click="$set('tab', 'archived')">Archived</x-ui.button>
            @endif
            @if ($can['restoreAny'])
                <x-ui.button variant="ghost" icon="heroicon-o-trash" :href="route('brand.leads.bin', ['brand' => $brand->slug])">Bin</x-ui.button>
            @endif
            @if ($can['export'])
                <x-ui.button variant="ghost" wire:click="export" icon="heroicon-o-arrow-down-tray">Export</x-ui.button>
            @endif
            @if ($can['import'])
                <x-ui.button variant="secondary" onclick="window.dispatchEvent(new CustomEvent('lq-open-import'))" icon="heroicon-o-arrow-up-tray">Import</x-ui.button>
            @endif
            @if ($can['create'])
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('brand.leads.create', ['brand' => $brand->slug])">Add lead</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card flush class="lq-list">
        {{-- Saved views on the left, search and filters on the right. --}}
        <div class="lq-viewbar">
            <div class="lq-views" role="tablist" aria-label="Lead views">
                @foreach ($tabs as $key => $item)
                    <button type="button" @class(['lq-view', 'lq-view-active' => $tab === $key]) wire:click="$set('tab', '{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                        {{ $item['label'] }}
                        @if ($item['count'] !== null)
                            <span @class(['lq-view-count', 'lq-view-count-'.$item['color']])>{{ $item['count'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="lq-viewbar-end">
                {{-- Newest first unless somebody picks another order (or clicks a column heading). --}}
                <label class="lq-sortpick" title="Sort the list">
                    <x-heroicon-o-arrows-up-down />
                    <select wire:change="$set('order', $event.target.value)" aria-label="Sort leads">
                        @if ($currentOrder === '')
                            <option value="" selected>By column</option>
                        @endif
                        @foreach (\App\Modules\Leads\Livewire\App\LeadsIndex::ORDERS as $key => [$label])
                            <option value="{{ $key }}" @selected($currentOrder === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="lq-search">
                    <x-heroicon-o-magnifying-glass />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, email or phone" aria-label="Search leads">
                </label>
                <button type="button" @class(['lq-btn lq-btn-secondary lq-btn-sm', 'lq-btn-on' => $showFilters || $hasFilters]) wire:click="$toggle('showFilters')" aria-expanded="{{ $showFilters ? 'true' : 'false' }}">
                    <x-heroicon-o-adjustments-horizontal />
                    Filters
                    @if ($hasFilters)
                        <span class="lq-count">{{ count($chips) }}</span>
                    @endif
                </button>
            </div>
        </div>

        @if ($showFilters)
            <div class="lq-filters">
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
        @endif

        @if ($hasFilters)
            <div class="lq-chips">
                @foreach ($chips as $key => $label)
                    <button type="button" class="lq-chip" wire:click="removeFilter('{{ $key }}')" wire:key="chip-{{ $key }}" title="Remove this filter">
                        {{ $label }}
                        <x-heroicon-m-x-mark />
                    </button>
                @endforeach
                <button type="button" class="lq-chip-clear" wire:click="clearFilters">Clear all</button>
            </div>
        @endif

        @if ($leads->isEmpty())
            <x-ui.empty :title="$search !== '' || $hasFilters ? 'No leads match' : ($archivedView ? 'Nothing archived' : ($agent && ! $team ? 'No leads assigned to you yet' : 'No leads yet'))" :description="$emptyText" :image="$search === '' && ! $hasFilters ? asset('images/brand/empty-leads.webp') : null" :icon="$search !== '' || $hasFilters ? 'heroicon-o-magnifying-glass' : null">
                <x-slot:actions>
                    @if ($search !== '' || $hasFilters)
                        <x-ui.button variant="secondary" wire:click="clearFilters">Clear search and filters</x-ui.button>
                    @elseif ($can['create'] && ! $archivedView)
                        <x-ui.button variant="primary" :href="route('brand.leads.create', ['brand' => $brand->slug])">Add your first lead</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty>
        @else
            <div class="lq-table-wrap">
                <table class="lq-table lq-table-rows">
                    <thead>
                        <tr>
                            <th class="lq-th-tick">
                                <input type="checkbox" class="lq-tick" aria-label="Select every lead on this page" x-data x-on:change="$wire.set('selected', $event.target.checked ? @js($leads->pluck('id')->all()) : [])" @checked(count($selected) > 0 && count($selected) === $leads->count())>
                            </th>
                            <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Lead" />
                            <x-ui.sort-th field="status_id" :sort="$sort" :direction="$direction" label="Status" />
                            <th>Source</th>
                            @if (! $agent || $team)
                                <th>Owner</th>
                            @endif
                            <x-ui.sort-th field="next_follow_up_at" :sort="$sort" :direction="$direction" label="Follow up" />
                            <x-ui.sort-th field="created_at" :sort="$sort" :direction="$direction" label="Received" class="lq-col-wide" />
                        </tr>
                    </thead>
                    <tbody class="lq-live">
                        @foreach ($leads as $lead)
                            @php $leadUrl = route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $lead->getKey()]); @endphp
                            <tr wire:key="lead-{{ $lead->getKey() }}" x-data x-on:click="$wire.open = {{ $lead->getKey() }}; $wire.openLead({{ $lead->getKey() }})" @class(['lq-row-click', 'lq-selected' => in_array($lead->getKey(), $selected, true), 'lq-row-open' => $previewLead?->is($lead)])>
                                <td class="lq-td-tick" x-data x-on:click.stop>
                                    <input type="checkbox" class="lq-tick" aria-label="Select {{ $lead->name }}" value="{{ $lead->getKey() }}" wire:model.live="selected">
                                </td>
                                <td class="lq-td-person">
                                    <span class="lq-person">
                                        <x-ui.avatar :name="$lead->name" tone />
                                        <span class="min-w-0">
                                            <a href="{{ $leadUrl }}" class="lq-cell-title" onclick="if (! (event.ctrlKey || event.metaKey || event.shiftKey)) event.preventDefault()" x-data x-on:click="($event.ctrlKey || $event.metaKey || $event.shiftKey) && $event.stopPropagation()">{{ $lead->name }}</a>
                                            <span class="lq-cell-sub">{{ ($fullContact[$lead->getKey()] ?? false) ? ($lead->email ?? $lead->phone) : ($lead->maskedEmail() ?? $lead->maskedPhone()) }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td><x-ui.status-pill :status="$lead->status" /></td>
                                <td><x-ui.lead-source :lead="$lead" /></td>
                                @if (! $agent || $team)
                                    <td>
                                        @if ($team && $lead->assigned_to === null)
                                            {{-- Front sales: one click and the lead is theirs (whoever clicks first). --}}
                                            <span class="lq-person">
                                                <button type="button" class="lq-btn lq-btn-secondary lq-btn-sm lq-take" x-on:click.stop wire:click="takeLead({{ $lead->getKey() }})" wire:loading.attr="disabled" wire:target="takeLead({{ $lead->getKey() }})" title="Take this lead: it becomes yours and nobody else can take it">
                                                    <x-heroicon-o-hand-raised /> Take
                                                </button>
                                                <span class="lq-cell-sub">{{ $lead->department?->name ?? 'No team yet' }}</span>
                                            </span>
                                        @elseif ($canAssign[$lead->getKey()] ?? false)
                                            {{-- One click opens the people, a second gives them the lead. The name shows at once, before the answer. --}}
                                            <button type="button" class="lq-owner" x-on:click.stop="openFor($el, { lead: {{ $lead->getKey() }}, dept: {{ $lead->department_id ?? 'null' }}, owner: {{ $lead->assigned_to ?? 'null' }} })" title="{{ $lead->assignee ? 'Give to someone else' : 'Assign this lead' }}" aria-haspopup="dialog">
                                                @if ($lead->assignee)
                                                    <x-ui.avatar :name="$lead->assignee->name" :src="$lead->assignee->avatarUrl()" size="sm" />
                                                @else
                                                    <span class="lq-avatar lq-avatar-sm lq-avatar-empty"><x-heroicon-m-user-plus /></span>
                                                @endif
                                                <span class="min-w-0">
                                                    <span @class(['lq-cell-name', 'lq-unassigned' => ! $lead->assignee]) x-text="pending[{{ $lead->getKey() }}] ?? @js($lead->assignee?->name ?? 'Unassigned')" x-bind:class="pending[{{ $lead->getKey() }}] && 'lq-owner-pending'">{{ $lead->assignee?->name ?? 'Unassigned' }}</span>
                                                    <span class="lq-cell-sub">{{ $lead->department?->name ?? 'No department yet' }}</span>
                                                </span>
                                                <x-heroicon-m-chevron-down class="lq-owner-caret" />
                                            </button>
                                        @else
                                            <span class="lq-person">
                                                @if ($lead->assignee)
                                                    <x-ui.avatar :name="$lead->assignee->name" :src="$lead->assignee->avatarUrl()" size="sm" />
                                                @else
                                                    <span class="lq-avatar lq-avatar-sm lq-avatar-empty"><x-heroicon-m-user /></span>
                                                @endif
                                                <span class="min-w-0">
                                                    <span @class(['lq-cell-name', 'lq-unassigned' => ! $lead->assignee])>{{ $lead->assignee?->name ?? 'Unassigned' }}</span>
                                                    <span class="lq-cell-sub">{{ $lead->department?->name ?? 'No department yet' }}</span>
                                                </span>
                                            </span>
                                        @endif
                                    </td>
                                @endif
                                <td><x-ui.due :at="$lead->next_follow_up_at" /></td>
                                <td class="lq-col-wide"><x-ui.received :at="$lead->created_at" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $leads->links('pagination.lq') }}
        @endif
    </x-ui.card>

    {{-- Bulk actions float at the bottom while leads are ticked. --}}
    @if (count($selected) > 0)
        <div class="lq-floatbar" role="region" aria-label="Actions for the selected leads">
            <span class="lq-floatbar-count"><span class="lq-count">{{ count($selected) }}</span> selected</span>
            <span class="lq-floatbar-sep" aria-hidden="true"></span>
            @unless ($agent)
                <button type="button" class="lq-floatbar-btn" wire:click="$set('showBulkAssign', true)"><x-heroicon-o-user-plus /> Assign</button>
            @endunless
            @if ($can['distribute'])
                <button type="button" class="lq-floatbar-btn" wire:click="$set('showBulkSend', true)"><x-heroicon-o-arrow-right-circle /> Send to team</button>
            @endif
            <button type="button" class="lq-floatbar-btn" wire:click="$set('showBulkStatus', true)"><x-heroicon-o-arrow-path /> Status</button>
            @if ($can['archiveAny'])
                @if ($archivedView)
                    <button type="button" class="lq-floatbar-btn" wire:click="bulkBringBack"><x-heroicon-o-arrow-uturn-left /> Bring back</button>
                @else
                    <button type="button" class="lq-floatbar-btn" wire:click="bulkArchive" title="Out of the lists, nothing deleted"><x-heroicon-o-archive-box /> Archive</button>
                @endif
            @endif
            @if ($can['deleteAny'])
                <button type="button" class="lq-floatbar-btn lq-floatbar-danger" wire:click="confirmBulkDelete"><x-heroicon-o-trash /> Delete</button>
            @endif
            <span class="lq-floatbar-sep" aria-hidden="true"></span>
            <button type="button" class="lq-floatbar-btn lq-floatbar-close" wire:click="$set('selected', [])" title="Clear the selection"><x-heroicon-m-x-mark /></button>
        </div>
    @endif

    @unless ($agent)
        <x-leads::assign-menu />
    @endunless

    @include('leads::livewire.app.partials.lead-sheet')

    {{-- Bulk modals --}}
    <x-ui.modal model="showBulkAssign" :title="'Assign '.count($selected).' '.str('lead')->plural(count($selected))" size="sm">
        <form wire:submit="bulkAssign" class="lq-form" id="bulk-assign-form">
            <x-ui.field label="Person" name="bulkAssignTo" required>
                <x-ui.select wire:model="bulkAssignTo" name="bulkAssignTo" placeholder="Choose a person…">
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
                    @foreach ($sendOptions as $id => $name)
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
        @livewire(\App\Modules\Leads\Livewire\App\LeadImport::class, ['brand' => $brand], key('lead-import'))
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
