<div class="flex flex-col gap-6">
    <x-ui.page-header title="Clients" subtitle="Everyone who has bought from this brand, by lifetime value. A repeat purchase joins the same client." />

    <x-ui.card flush>
        <div class="lq-toolbar">
            <div class="relative w-full sm:w-72">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Search by name, email or phone…">
            </div>
        </div>

        @if ($clients->isEmpty())
            <x-ui.empty title="No clients yet" description="A lead becomes a client when its first sale is recorded with Mark as Won." icon="heroicon-o-user-group">
                <x-slot:actions>
                    <x-ui.button variant="secondary" :href="route('brand.leads.index', ['brand' => $brand->slug])">Open Leads</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr>
                            <x-ui.sort-th field="name" :sort="$sort" :direction="$direction" label="Client" />
                            <th>Contact</th>
                            <x-ui.sort-th field="sales_count" :sort="$sort" :direction="$direction" label="Sales" align="end" />
                            <x-ui.sort-th field="lifetime_value" :sort="$sort" :direction="$direction" label="Lifetime value" align="end" />
                            <x-ui.sort-th field="last_sale_at" :sort="$sort" :direction="$direction" label="Last sale" />
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $client)
                            <tr wire:key="client-{{ $client->getKey() }}">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$client->name" size="sm" tone />
                                        <a href="{{ route('brand.clients.show', ['brand' => $brand->slug, 'client' => $client]) }}" class="lq-cell-title" wire:navigate>{{ $client->name }}</a>
                                    </div>
                                </td>
                                <td class="lq-muted">{{ $client->email ?? $client->phone ?? '—' }}</td>
                                <td class="lq-table-end lq-num">{{ $client->sales_count }}</td>
                                <td class="lq-table-end lq-num">{{ \App\Modules\Sales\Support\Money::format($client->lifetime_value, $brand->currency) }}</td>
                                <td class="lq-muted">{{ $client->last_sale_at?->format('j M Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $clients->links('pagination.lq') }}
        @endif
    </x-ui.card>
</div>
