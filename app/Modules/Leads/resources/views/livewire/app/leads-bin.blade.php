<div class="flex flex-col gap-6" wire:poll.60s.visible data-live-refresh>
    <x-ui.page-header title="Bin" subtitle="Leads deleted in {{ $brand->name }}. Restore brings a lead back with its notes, follow-ups, chat and history; after {{ $keepDays }} days in the bin a lead is deleted for good." :breadcrumbs="['Leads' => route('brand.leads.index', ['brand' => $brand->slug]), 'Bin' => null]">
        <x-slot:actions>
            <x-ui.button variant="ghost" icon="heroicon-o-arrow-left" :href="route('brand.leads.index', ['brand' => $brand->slug])">Back to Leads</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($summary['total'] > 0)
        <div class="lq-minis">
            <x-ui.mini label="In the bin" :value="number_format($summary['total'])" icon="heroicon-o-trash" />
            <x-ui.mini label="Deleted in the last 7 days" :value="number_format($summary['week'])" icon="heroicon-o-calendar-days" />
            <x-ui.mini label="Next deleted for good" :value="$summary['next'] === 0 ? 'Tonight' : 'In '.$summary['next'].' '.str('day')->plural($summary['next'])" icon="heroicon-o-clock" :tone="$summary['next'] !== null && $summary['next'] <= 3 ? 'warn' : null" :hint="'A lead is deleted for good '.$keepDays.' days after it went into the bin'" />
        </div>
    @endif

    <x-ui.card flush class="lq-list">
        @if ($summary['total'] === 0)
            <x-ui.empty title="The bin is empty" :description="'A lead you delete waits here for '.$keepDays.' days, and an Admin can bring it back with Restore.'" icon="heroicon-o-trash">
                <x-slot:actions>
                    <x-ui.button variant="secondary" :href="route('brand.leads.index', ['brand' => $brand->slug])">Open Leads</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        @else
            <div class="lq-viewbar">
                <div class="lq-views" role="tablist" aria-label="Bin views">
                    <span class="lq-view lq-view-active" role="tab" aria-selected="true">
                        Deleted leads
                        <span class="lq-view-count">{{ $summary['total'] }}</span>
                    </span>
                </div>
                <div class="lq-viewbar-end">
                    <label class="lq-search">
                        <x-heroicon-o-magnifying-glass />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, email or phone" aria-label="Search the bin">
                    </label>
                </div>
            </div>

            @if ($leads->isEmpty())
                <x-ui.empty title="Nothing in the bin matches" description="Try another name, email or phone." icon="heroicon-o-magnifying-glass">
                    <x-slot:actions>
                        <x-ui.button variant="secondary" wire:click="$set('search', '')">Clear search</x-ui.button>
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
                                <th>Status</th>
                                <th class="lq-col-wide">Source</th>
                                <th>Owner</th>
                                <x-ui.sort-th field="deleted_at" :sort="$sort" :direction="$direction" label="Deleted" />
                                <th>Deleted for good</th>
                                <th class="lq-table-end"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="lq-live">
                            @foreach ($leads as $lead)
                                @php $deletedBy = $lead->activities->first()?->causer; @endphp
                                <tr wire:key="binned-{{ $lead->getKey() }}" @class(['lq-selected' => in_array($lead->getKey(), $selected, true)])>
                                    <td class="lq-td-tick">
                                        <input type="checkbox" class="lq-tick" aria-label="Select {{ $lead->name }}" value="{{ $lead->getKey() }}" wire:model.live="selected">
                                    </td>
                                    <td class="lq-td-person">
                                        <span class="lq-person">
                                            <x-ui.avatar :name="$lead->name" tone />
                                            <span class="min-w-0">
                                                <span class="lq-cell-title">{{ $lead->name }}</span>
                                                <span class="lq-cell-sub">{{ $lead->email ?? $lead->phone ?? 'No email or phone' }}</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td><x-ui.status-pill :status="$lead->status" /></td>
                                    <td class="lq-col-wide"><x-ui.lead-source :lead="$lead" /></td>
                                    <td>
                                        <span class="lq-person">
                                            @if ($lead->assignee)
                                                <x-ui.avatar :name="$lead->assignee->name" :src="$lead->assignee->avatarUrl()" size="sm" />
                                            @else
                                                <span class="lq-avatar lq-avatar-sm lq-avatar-empty"><x-heroicon-m-user /></span>
                                            @endif
                                            <span class="min-w-0">
                                                <span @class(['lq-cell-name', 'lq-unassigned' => ! $lead->assignee])>{{ $lead->assignee?->name ?? 'Unassigned' }}</span>
                                                <span class="lq-cell-sub">
                                                    @if (isset($removedTeams[$lead->department_id]))
                                                        {{ $removedTeams[$lead->department_id] }} (team removed)
                                                    @else
                                                        {{ $lead->department?->name ?? 'No department' }}
                                                    @endif
                                                </span>
                                            </span>
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="lq-cell-name" title="{{ $lead->deleted_at?->format('j M Y, H:i') }}">{{ $lead->deleted_at?->diffForHumans() }}</span>
                                        <span class="lq-cell-sub">{{ $deletedBy ? 'by '.$deletedBy->name : 'by an Admin' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        @php $left = $daysLeft[$lead->getKey()]; @endphp
                                        <x-ui.badge :color="$left <= 3 ? 'warning' : 'gray'" icon="heroicon-m-clock" :title="'Unless it is restored, this lead is deleted for good '.$lead->deleted_at?->addDays($keepDays)->format('j M Y')">{{ $left === 0 ? 'Tonight' : 'In '.$left.' '.str('day')->plural($left) }}</x-ui.badge>
                                    </td>
                                    <td class="lq-table-end">
                                        <div class="lq-row-tools">
                                            <x-ui.button size="sm" icon="heroicon-o-arrow-uturn-left" wire:click="restore({{ $lead->getKey() }})" wire:loading.attr="disabled" wire:target="restore({{ $lead->getKey() }})">Restore</x-ui.button>
                                            @if ($canDelete)
                                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmDeleteForever({{ $lead->getKey() }})" title="Delete forever" aria-label="Delete {{ $lead->name }} forever"><x-heroicon-o-trash /></button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $leads->links('pagination.lq') }}
            @endif
        @endif
    </x-ui.card>

    {{-- Restore every ticked lead at once. --}}
    @if (count($selected) > 0)
        <div class="lq-floatbar" role="region" aria-label="Actions for the selected leads">
            <span class="lq-floatbar-count"><span class="lq-count">{{ count($selected) }}</span> selected</span>
            <span class="lq-floatbar-sep" aria-hidden="true"></span>
            <button type="button" class="lq-floatbar-btn" wire:click="restoreSelected"><x-heroicon-o-arrow-uturn-left /> Restore</button>
            @if ($canDelete)
                <button type="button" class="lq-floatbar-btn lq-floatbar-danger" wire:click="confirmDeleteSelected"><x-heroicon-o-trash /> Delete forever</button>
            @endif
            <span class="lq-floatbar-sep" aria-hidden="true"></span>
            <button type="button" class="lq-floatbar-btn lq-floatbar-close" wire:click="$set('selected', [])" title="Clear the selection"><x-heroicon-m-x-mark /></button>
        </div>
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
