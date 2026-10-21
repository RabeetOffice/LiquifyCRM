@php
    use App\Modules\Sales\Enums\ClientStage;
    use App\Modules\Sales\Support\Money;
@endphp
<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header :title="$mineOnly ? 'My clients' : 'Clients'" :subtitle="$mineOnly ? 'The clients you look after. Record every upsell on the client, under your name.' : 'Everyone who has bought from this brand, and who looks after them after the first sale.'">
        @if ($canImport)
            <x-slot:actions>
                <x-ui.button variant="secondary" onclick="window.dispatchEvent(new CustomEvent('lq-open-import'))" icon="heroicon-o-arrow-up-tray">Import clients</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($any)
        <div class="lq-minis">
            <x-ui.mini :label="$tab === 'closed' ? 'Closed clients' : 'Clients'" :value="number_format($summary['clients'])" icon="heroicon-o-user-group" />
            <x-ui.mini label="Lifetime value" :value="Money::format($summary['value'], $brand->currency)" icon="heroicon-o-banknotes" />
            @if ($paymentsReady)
                <x-ui.mini label="Still to collect" :value="Money::format($summary['due'], $brand->currency)" icon="heroicon-o-clock" :tone="$summary['due'] > 0 ? 'warn' : null" hint="What clients still owe on deals they paid part of" />
            @endif
            @if ($ready)
                <x-ui.mini label="From upsells" :value="Money::format($summary['upsell'], $brand->currency)" icon="heroicon-o-arrow-trending-up" :tone="$summary['upsell'] > 0 ? 'ok' : null" :hint="$summary['value'] > 0 ? round($summary['upsell'] / $summary['value'] * 100).'% of the lifetime value came after the first sale' : null" />
            @else
                <x-ui.mini label="Average per client" :value="Money::format(round($summary['average']), $brand->currency)" icon="heroicon-o-scale" />
            @endif
            <x-ui.mini label="Bought again" :value="$summary['returning'].' of '.$summary['clients']" icon="heroicon-o-arrow-path" hint="Clients with more than one sale" />
        </div>
    @endif

    <x-ui.card flush class="lq-list">
        @if (! $any)
            <x-ui.empty :title="$mineOnly ? 'No clients given to you yet' : 'No clients yet'" :description="$mineOnly ? 'When front sales hands a client to you, or your Manager gives you one, it appears here with a notification.' : 'A lead becomes a client when its first sale is recorded with Mark as Won.'" icon="heroicon-o-user-group">
                @unless ($mineOnly)
                    <x-slot:actions>
                        <x-ui.button variant="secondary" :href="route('brand.leads.index', ['brand' => $brand->slug])">Open Leads</x-ui.button>
                        @if ($canImport)
                            <x-ui.button variant="secondary" onclick="window.dispatchEvent(new CustomEvent('lq-open-import'))" icon="heroicon-o-arrow-up-tray">Import clients</x-ui.button>
                        @endif
                    </x-slot:actions>
                @endunless
            </x-ui.empty>
        @else
            {{-- Where the clients stand as views; search and the order beside them. --}}
            <div class="lq-viewbar">
                <div class="lq-views" role="tablist" aria-label="Client views">
                    @foreach ($tabs as $key => $label)
                        <button type="button" @class(['lq-view', 'lq-view-active' => $tab === $key]) wire:click="$set('tab', '{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                            {{ $label }}
                            @if (($counts[$key] ?? 0) > 0)
                                <span @class(['lq-view-count', 'lq-view-count-primary' => $key === 'waiting'])>{{ $counts[$key] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
                <div class="lq-viewbar-end">
                    <label class="lq-search">
                        <x-heroicon-o-magnifying-glass />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, email or phone" aria-label="Search clients">
                    </label>
                    <select class="lq-input lq-input-sm lq-order" aria-label="Order of the list" wire:change="order($event.target.value)">
                        @foreach ($orders as $key => [, , $label])
                            <option value="{{ $key }}" @selected($order === $key)>{{ $label }}</option>
                        @endforeach
                        @if ($order === null)
                            <option value="" selected>Your own order</option>
                        @endif
                    </select>
                </div>
            </div>

            @if ($clients->isEmpty())
                @if ($search !== '')
                    <x-ui.empty title="No client matches" description="Try another name, email or phone." icon="heroicon-o-magnifying-glass">
                        <x-slot:actions>
                            <x-ui.button variant="secondary" wire:click="$set('search', '')">Clear search</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty>
                @else
                    <x-ui.empty :title="['waiting' => 'Nobody is waiting', 'front' => 'Every client is handed over', 'closed' => 'No closed clients', 'mine' => 'You look after no client yet'][$tab] ?? 'No clients here'" :description="['waiting' => 'Every client in an Upsell team has a person.', 'front' => 'A client shows here after the first sale, until front sales hands them to an Upsell team.', 'closed' => 'A client is closed from their own page when the work is over.'][$tab] ?? null" icon="heroicon-o-check-circle" />
                @endif
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table lq-table-rows">
                        <thead>
                            <tr>
                                @if ($canGive)
                                    <th class="lq-th-tick">
                                        <input type="checkbox" class="lq-tick" aria-label="Select every client on this page" x-data x-on:change="$wire.set('selected', $event.target.checked ? @js($clients->pluck('id')->all()) : [])" @checked(count($selected) > 0 && count($selected) === $clients->count())>
                                    </th>
                                @endif
                                <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Client" />
                                @if ($ready)
                                    <th>Looked after by</th>
                                @endif
                                <x-ui.sort-th field="sales_count" :sort="$sort" :direction="$direction" label="Sales" align="end" />
                                <x-ui.sort-th field="lifetime_value" :sort="$sort" :direction="$direction" label="Lifetime value" align="end" />
                                <x-ui.sort-th field="first_sale_at" :sort="$sort" :direction="$direction" label="Client since" class="lq-col-wide" />
                                <x-ui.sort-th field="last_sale_at" :sort="$sort" :direction="$direction" label="Last sale" />
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($clients as $client)
                                @php $stage = $client->stage(); @endphp
                                {{-- The whole row opens the client; the name is the real link. --}}
                                <tr wire:key="client-{{ $client->getKey() }}" @class(['lq-row-click', 'lq-selected' => in_array($client->getKey(), $selected, true)]) x-data x-on:click="$event.target.closest('a, input, .lq-td-tick') || $el.querySelector('a.lq-cell-title').click()">
                                    @if ($canGive)
                                        <td class="lq-td-tick">
                                            <input type="checkbox" class="lq-tick" aria-label="Select {{ $client->name }}" value="{{ $client->getKey() }}" wire:model.live="selected">
                                        </td>
                                    @endif
                                    <td class="lq-td-person">
                                        <span class="lq-person">
                                            <x-ui.avatar :name="$client->name" tone />
                                            <span class="min-w-0">
                                                <a href="{{ route('brand.clients.show', ['brand' => $brand->slug, 'client' => $client]) }}" class="lq-cell-title" wire:navigate>{{ $client->name }}</a>
                                                <span class="lq-cell-sub">{{ $client->email ?? $client->phone ?? 'No contact details' }}</span>
                                            </span>
                                        </span>
                                    </td>
                                    @if ($ready)
                                        <td>
                                            @if ($client->owner)
                                                <span class="lq-person">
                                                    <x-ui.avatar :name="$client->owner->name" :src="$client->owner->avatarUrl()" size="sm" />
                                                    <span class="min-w-0">
                                                        <span class="lq-cell-name">{{ $client->owner->name }}</span>
                                                        <span class="lq-cell-sub">{{ $stage === ClientStage::Closed ? 'Closed' : ($client->department?->name ?? 'Upsell') }}</span>
                                                    </span>
                                                </span>
                                            @elseif ($stage === ClientStage::Closed)
                                                <x-ui.badge color="gray" icon="heroicon-m-lock-closed">Closed</x-ui.badge>
                                            @elseif ($stage === ClientStage::Waiting)
                                                <x-ui.badge color="warning" icon="heroicon-m-clock">Waiting in {{ $client->department?->name ?? 'Upsell' }}</x-ui.badge>
                                            @else
                                                <x-ui.badge color="gray" icon="heroicon-m-arrow-right-circle">Not handed over</x-ui.badge>
                                            @endif
                                        </td>
                                    @endif
                                    @php
                                        // A client this person is not on: only what their own team sold (front sales does not see Upsell's sales).
                                        $own = $shown[$client->getKey()] ?? null;
                                        $worth = $own['value'] ?? (float) $client->lifetime_value;
                                    @endphp
                                    <td class="lq-table-end">
                                        <span class="lq-num">{{ $own['sales'] ?? $client->sales_count }}</span>
                                        @if ($ready && $own === null && $client->upsells_count > 0)
                                            <span class="lq-cell-sub">{{ $client->upsells_count }} {{ str('upsell')->plural($client->upsells_count) }}</span>
                                        @endif
                                    </td>
                                    <td class="lq-table-end">
                                        <span class="lq-worth" @if ($own !== null) title="Sold by your team" @endif>
                                            <span class="lq-worth-value lq-tabular">{{ Money::format($worth, $brand->currency) }}</span>
                                            <span class="lq-worth-bar"><span style="width: {{ $summary['top'] > 0 ? max(4, (int) round($worth / $summary['top'] * 100)) : 0 }}%"></span></span>
                                        </span>
                                        @if (($dues[$client->getKey()] ?? 0) > 0)
                                            <span class="lq-cell-sub lq-due-owed">{{ Money::format($dues[$client->getKey()], $brand->currency) }} to collect</span>
                                        @endif
                                    </td>
                                    <td class="lq-muted whitespace-nowrap lq-col-wide">{{ $client->first_sale_at?->format('j M Y') ?? '—' }}</td>
                                    <td class="whitespace-nowrap" title="{{ $client->last_sale_at?->format('j M Y') }}">{{ $client->last_sale_at?->diffForHumans() ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $clients->links('pagination.lq') }}
            @endif
        @endif
    </x-ui.card>

    @if ($canGive && count($selected) > 0)
        <div class="lq-floatbar" role="region" aria-label="Actions for the selected clients">
            <span class="lq-floatbar-count"><span class="lq-count">{{ count($selected) }}</span> selected</span>
            <span class="lq-floatbar-sep" aria-hidden="true"></span>
            <button type="button" class="lq-floatbar-btn" wire:click="openGive"><x-heroicon-o-arrows-right-left /> Give to…</button>
            <span class="lq-floatbar-sep" aria-hidden="true"></span>
            <button type="button" class="lq-floatbar-btn lq-floatbar-close" wire:click="$set('selected', [])" title="Clear the selection"><x-heroicon-m-x-mark /></button>
        </div>
    @endif

    @if ($canGive)
        <x-ui.modal model="showGive" :title="'Give '.count($selected).' '.str('client')->plural(count($selected)).' to'" description="A person looks after them from now on. Give them to a team instead and it shares them its own way." size="sm">
            @if ($teams === [])
                <x-ui.empty title="No Upsell department yet" description="Create a department and set what it does to Upsell. Its people can then receive clients." icon="heroicon-o-rectangle-group">
                    <x-slot:actions>
                        <x-ui.button variant="secondary" :href="route('brand.departments.index', ['brand' => $brand->slug])">Open Departments</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty>
            @else
                <form wire:submit="give" class="lq-form" id="give-form">
                    <x-ui.field label="Team or person" name="giveTo" required>
                        <x-ui.select wire:model="giveTo" name="giveTo" placeholder="Choose a team or a person…">
                            @foreach ($teams as $team)
                                <optgroup label="{{ $team['department']->name }}">
                                    <option value="{{ $team['department']->getKey() }}">{{ $team['department']->name }}: {{ $team['department']->assignment_mode === \App\Modules\Core\Enums\AssignmentMode::RoundRobin ? 'shared in turn (round robin)' : 'the team decides' }}</option>
                                    @foreach ($team['people'] as $id => $name)
                                        <option value="{{ $team['department']->getKey() }}:{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </form>
            @endif
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showGive', false)">Cancel</x-ui.button>
                @if ($teams !== [])
                    <x-ui.button type="submit" form="give-form" variant="primary" wire:loading.attr="disabled" wire:target="give">Give</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($canImport)
        @livewire(\App\Modules\Sales\Livewire\App\ClientImport::class, ['brand' => $brand], key('client-import'))
    @endif
</div>
