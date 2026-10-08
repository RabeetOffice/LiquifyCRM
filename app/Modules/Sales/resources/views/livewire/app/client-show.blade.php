@php use App\Modules\Sales\Support\Money; @endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$client->name" :subtitle="$client->email ?? $client->phone" :breadcrumbs="['Clients' => route('brand.clients.index', ['brand' => $brand->slug]), $client->name => null]" />

    <div class="lq-grid-stats lq-grid-stats-3">
        <x-ui.stat label="Lifetime value" :value="Money::format($client->lifetime_value, $brand->currency)" icon="heroicon-o-banknotes" hero />
        <x-ui.stat label="Sales" :value="$client->sales_count" icon="heroicon-o-trophy" :description="$client->first_sale_at ? 'Client since '.$client->first_sale_at->format('j M Y') : null" />
        <x-ui.stat label="Last sale" :value="$client->last_sale_at?->format('j M Y')" icon="heroicon-o-calendar-days" :description="$client->last_sale_at?->diffForHumans()" />
    </div>

    <x-ui.card title="Sales" description="Every purchase and who closed it." flush>
        <div class="lq-table-wrap">
            <table class="lq-table">
                <thead><tr><th>Closed</th><th>Service</th><th>Closed by</th><th>Department</th><th class="lq-table-end">Amount</th></tr></thead>
                <tbody>
                    @foreach ($sales as $sale)
                        <tr wire:key="sale-{{ $sale->getKey() }}">
                            <td class="whitespace-nowrap">{{ $sale->closed_at->format('j M Y') }}</td>
                            <td>{{ $sale->service ?? '—' }}</td>
                            <td>{{ $sale->closer?->name ?? '—' }}</td>
                            <td class="lq-muted">{{ $sale->department?->name ?? '—' }}</td>
                            <td class="lq-table-end lq-num">
                                @if ($sale->lead_id)
                                    <a class="lq-link" href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $sale->lead_id]) }}" wire:navigate>{{ Money::format($sale->amount, $sale->currency) }}</a>
                                @else
                                    {{ Money::format($sale->amount, $sale->currency) }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.card title="Leads" description="Every enquiry from this person." flush>
        @if ($leads->isEmpty())
            <p class="lq-help px-6 py-6">No leads you can see.</p>
        @else
            <ul class="flex flex-col">
                @foreach ($leads as $lead)
                    <li class="lq-rank" wire:key="lead-{{ $lead->getKey() }}">
                        <span class="min-w-0 flex-1">
                            <a class="lq-cell-title" href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $lead]) }}" wire:navigate>{{ $lead->source_type->getLabel() }} · {{ $lead->created_at?->format('j M Y') }}</a>
                            <span class="lq-cell-sub">{{ $lead->assignee?->name ? 'Owner: '.$lead->assignee->name : 'Unassigned' }}</span>
                        </span>
                        <x-ui.status-pill :status="$lead->status" />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
