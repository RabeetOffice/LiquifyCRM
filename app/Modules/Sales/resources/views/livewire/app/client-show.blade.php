@php use App\Modules\Sales\Support\Money; @endphp
<div class="flex flex-col gap-5">
    <nav class="lq-crumbbar" aria-label="Breadcrumb">
        <x-ui.button size="sm" variant="ghost" icon="heroicon-o-arrow-left" :href="route('brand.clients.index', ['brand' => $brand->slug])">Clients</x-ui.button>
        <span class="lq-muted" aria-hidden="true">/</span>
        <span class="truncate">{{ $client->name }}</span>
    </nav>

    <header class="lq-lead-hero">
        <x-ui.avatar :name="$client->name" size="lg" tone />
        <div class="min-w-0 flex-1">
            <h1 class="lq-lead-name lq-lead-name-page">{{ $client->name }}</h1>
            <div class="lq-lead-meta">
                <x-ui.badge color="success" icon="heroicon-m-check-badge">Client</x-ui.badge>
                @if ($client->first_sale_at)
                    <span>since {{ $client->first_sale_at->format('j M Y') }}</span>
                @endif
                @if ($client->sales_count > 1)
                    <span>· bought {{ $client->sales_count }} times</span>
                @endif
            </div>
        </div>
    </header>

    <div class="lq-split">
        <div class="lq-split-main">
            <x-ui.card title="Sales" description="Every purchase and who closed it." flush>
                <div class="lq-table-wrap">
                    <table class="lq-table lq-table-rows">
                        <thead>
                            <tr>
                                <th>What</th>
                                <th>Closed by</th>
                                <th class="lq-table-end">Amount</th>
                                @if ($canCorrect)
                                    <th class="lq-table-end">Actions</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sales as $sale)
                                <tr wire:key="sale-{{ $sale->getKey() }}">
                                    <td>
                                        <span class="lq-cell-title">{{ $sale->service ?? 'Sale' }}</span>
                                        <span class="lq-cell-sub">{{ $sale->closed_at->format('j M Y') }}</span>
                                    </td>
                                    <td>
                                        @if ($sale->closer)
                                            <span class="lq-person">
                                                <x-ui.avatar :name="$sale->closer->name" :src="$sale->closer->avatarUrl()" size="sm" />
                                                <span class="min-w-0">
                                                    <span class="lq-cell-name">{{ $sale->closer->name }}</span>
                                                    <span class="lq-cell-sub">{{ $sale->department?->name ?? 'No department' }}</span>
                                                </span>
                                            </span>
                                        @else
                                            <span class="lq-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="lq-table-end lq-num">
                                        @if ($sale->lead_id)
                                            <a class="lq-sale-amount" href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $sale->lead_id]) }}" title="Open the lead this sale came from" wire:navigate>{{ Money::format($sale->amount, $sale->currency) }}</a>
                                        @else
                                            <span class="lq-sale-amount">{{ Money::format($sale->amount, $sale->currency) }}</span>
                                        @endif
                                    </td>
                                    @if ($canCorrect)
                                        <td class="lq-table-end">
                                            <div class="lq-row-tools">
                                                <x-ui.button size="sm" variant="ghost" icon="heroicon-o-pencil-square" wire:click="edit({{ $sale->getKey() }})">Correct</x-ui.button>
                                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmRemove({{ $sale->getKey() }})" title="Remove" aria-label="Remove this sale"><x-heroicon-o-trash /></button>
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card title="Enquiries" description="Every lead from this person that you may see." flush>
                @if ($leads->isEmpty())
                    <p class="lq-help px-6 py-6">No leads you can see.</p>
                @else
                    <ul class="lq-linklist">
                        @foreach ($leads as $lead)
                            <li wire:key="lead-{{ $lead->getKey() }}">
                                <a class="lq-linkrow" href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $lead]) }}" wire:navigate>
                                    <x-ui.lead-source :lead="$lead" />
                                    <span class="min-w-0 flex-1">
                                        <span class="lq-cell-title">Received {{ $lead->created_at?->format('j M Y') }}</span>
                                        <span class="lq-cell-sub">{{ $lead->assignee?->name ? 'Owner: '.$lead->assignee->name : 'Unassigned' }}</span>
                                    </span>
                                    <x-ui.status-pill :status="$lead->status" />
                                    <x-heroicon-m-chevron-right class="lq-linkrow-arrow" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <aside class="lq-split-side">
            <x-ui.stat label="Lifetime value" :value="Money::format($client->lifetime_value, $brand->currency)" icon="heroicon-o-banknotes" hero />

            <section class="lq-card">
                <div class="lq-card-body">
                    <dl class="lq-kv">
                        <div><dt>Sales</dt><dd class="lq-tabular">{{ $client->sales_count }}</dd></div>
                        <div><dt>Average sale</dt><dd class="lq-tabular">{{ $client->sales_count > 0 ? Money::format(round((float) $client->lifetime_value / $client->sales_count), $brand->currency) : '—' }}</dd></div>
                        <div><dt>First sale</dt><dd>{{ $client->first_sale_at?->format('j M Y') ?? '—' }}</dd></div>
                        <div><dt>Last sale</dt><dd title="{{ $client->last_sale_at?->format('j M Y') }}">{{ $client->last_sale_at?->diffForHumans() ?? '—' }}</dd></div>
                    </dl>
                </div>
            </section>

            <section class="lq-card">
                <div class="lq-card-body">
                    <h2 class="lq-lead-heading">Contact</h2>
                    <ul class="lq-facts">
                        <li class="lq-fact">
                            <span class="lq-fact-icon"><x-heroicon-o-phone /></span>
                            <span class="lq-fact-text">
                                <span class="lq-fact-label">Phone</span>
                                @if ($client->phone)
                                    <a href="tel:{{ $client->phone }}" class="lq-fact-value lq-tabular hover:underline">{{ $client->phone }}</a>
                                @else
                                    <span class="lq-fact-value lq-muted">No phone</span>
                                @endif
                            </span>
                            @if ($client->phone)
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-data="copy(@js($client->phone))" x-on:click="copy()" x-bind:title="copied ? 'Copied' : 'Copy phone'">
                                    <x-heroicon-o-clipboard x-show="! copied" />
                                    <x-heroicon-o-check x-show="copied" x-cloak />
                                </button>
                            @endif
                        </li>
                        <li class="lq-fact">
                            <span class="lq-fact-icon"><x-heroicon-o-envelope /></span>
                            <span class="lq-fact-text">
                                <span class="lq-fact-label">Email</span>
                                @if ($client->email)
                                    <a href="mailto:{{ $client->email }}" class="lq-fact-value lq-fact-clip hover:underline" title="{{ $client->email }}">{{ $client->email }}</a>
                                @else
                                    <span class="lq-fact-value lq-muted">No email</span>
                                @endif
                            </span>
                            @if ($client->email)
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-data="copy(@js($client->email))" x-on:click="copy()" x-bind:title="copied ? 'Copied' : 'Copy email'">
                                    <x-heroicon-o-clipboard x-show="! copied" />
                                    <x-heroicon-o-check x-show="copied" x-cloak />
                                </button>
                            @endif
                        </li>
                    </ul>
                </div>
            </section>
        </aside>
    </div>

    @if ($canCorrect)
        <x-ui.modal model="showEdit" title="Correct this sale" description="Targets and this client's lifetime value are counted again. The change is written to the lead's timeline." size="sm">
            <form wire:submit="update" class="lq-form" id="sale-edit-form">
                <x-ui.field label="Amount" name="amount" required>
                    <div class="lq-input-group">
                        <span class="lq-input-prefix">{{ $brand->currency }}</span>
                        <x-ui.input type="number" step="0.01" min="0" wire:model="amount" name="amount" inputmode="decimal" />
                    </div>
                </x-ui.field>
                <x-ui.field label="Service or package" name="service">
                    <x-ui.input wire:model="service" name="service" />
                </x-ui.field>
                <x-ui.field label="Close date" name="closedAt" required tip="Moving the date to another month moves the sale to that month's targets.">
                    <x-ui.input type="date" wire:model="closedAt" name="closedAt" max="{{ now()->toDateString() }}" />
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showEdit', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="sale-edit-form" variant="primary" wire:loading.attr="disabled" wire:target="update">Save correction</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.confirm :confirm="$confirm" />
    @endif
</div>
