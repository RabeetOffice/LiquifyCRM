@php use App\Modules\Sales\Support\Money; @endphp
<div class="flex flex-col gap-6" data-live-refresh>
    <x-ui.page-header title="Clients" subtitle="Everyone who has bought from this brand. A repeat purchase joins the same client." />

    @if ($summary['clients'] > 0)
        <div class="lq-minis">
            <x-ui.mini label="Clients" :value="number_format($summary['clients'])" icon="heroicon-o-user-group" />
            <x-ui.mini label="Lifetime value" :value="Money::format($summary['value'], $brand->currency)" icon="heroicon-o-banknotes" />
            <x-ui.mini label="Average per client" :value="Money::format(round($summary['average']), $brand->currency)" icon="heroicon-o-scale" />
            <x-ui.mini label="Bought again" :value="$summary['returning'].' of '.$summary['clients']" icon="heroicon-o-arrow-path" hint="Clients with more than one sale" />
        </div>
    @endif

    <x-ui.card flush class="lq-list">
        @if ($summary['clients'] === 0)
            <x-ui.empty title="No clients yet" description="A lead becomes a client when its first sale is recorded with Mark as Won." icon="heroicon-o-user-group">
                <x-slot:actions>
                    <x-ui.button variant="secondary" :href="route('brand.leads.index', ['brand' => $brand->slug])">Open Leads</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        @else
            {{-- The order of the list as views, search beside them. --}}
            <div class="lq-viewbar">
                <div class="lq-views" role="tablist" aria-label="Order of the list">
                    @foreach ($orders as $key => [, , $label])
                        <button type="button" @class(['lq-view', 'lq-view-active' => $order === $key]) wire:click="order('{{ $key }}')" role="tab" aria-selected="{{ $order === $key ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="lq-viewbar-end">
                    <label class="lq-search">
                        <x-heroicon-o-magnifying-glass />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, email or phone" aria-label="Search clients">
                    </label>
                </div>
            </div>

            @if ($clients->isEmpty())
                <x-ui.empty title="No client matches" description="Try another name, email or phone." icon="heroicon-o-magnifying-glass">
                    <x-slot:actions>
                        <x-ui.button variant="secondary" wire:click="$set('search', '')">Clear search</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty>
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table lq-table-rows">
                        <thead>
                            <tr>
                                <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Client" />
                                <x-ui.sort-th field="sales_count" :sort="$sort" :direction="$direction" label="Sales" align="end" />
                                <x-ui.sort-th field="lifetime_value" :sort="$sort" :direction="$direction" label="Lifetime value" align="end" />
                                <x-ui.sort-th field="first_sale_at" :sort="$sort" :direction="$direction" label="Client since" class="lq-col-wide" />
                                <x-ui.sort-th field="last_sale_at" :sort="$sort" :direction="$direction" label="Last sale" />
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($clients as $client)
                                {{-- The whole row opens the client; the name is the real link. --}}
                                <tr wire:key="client-{{ $client->getKey() }}" class="lq-row-click" x-data x-on:click="$event.target.closest('a') || $el.querySelector('a.lq-cell-title').click()">
                                    <td class="lq-td-person">
                                        <span class="lq-person">
                                            <x-ui.avatar :name="$client->name" tone />
                                            <span class="min-w-0">
                                                <a href="{{ route('brand.clients.show', ['brand' => $brand->slug, 'client' => $client]) }}" class="lq-cell-title" wire:navigate>{{ $client->name }}</a>
                                                <span class="lq-cell-sub">{{ $client->email ?? $client->phone ?? 'No contact details' }}</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td class="lq-table-end lq-num">{{ $client->sales_count }}</td>
                                    <td class="lq-table-end">
                                        <span class="lq-worth">
                                            <span class="lq-worth-value lq-tabular">{{ Money::format($client->lifetime_value, $brand->currency) }}</span>
                                            <span class="lq-worth-bar"><span style="width: {{ $summary['top'] > 0 ? max(4, (int) round((float) $client->lifetime_value / $summary['top'] * 100)) : 0 }}%"></span></span>
                                        </span>
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
</div>
