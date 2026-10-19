@php
    use App\Modules\Sales\Enums\ClientStage;
    use App\Modules\Sales\Support\Money;

    $currency = $brand->currency;
    $frontShare = $totals['all'] > 0 ? (int) round($totals['front'] / $totals['all'] * 100) : 0;
@endphp
<div class="flex flex-col gap-5" data-live-refresh>
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
                @if ($stage === ClientStage::Closed)
                    <x-ui.badge color="gray" icon="heroicon-m-lock-closed">Closed client</x-ui.badge>
                @else
                    <x-ui.badge color="success" icon="heroicon-m-check-badge">Client</x-ui.badge>
                @endif
                @if ($ready && $stage === ClientStage::Waiting)
                    <x-ui.badge color="warning" icon="heroicon-m-clock">Waiting for a person</x-ui.badge>
                @elseif ($ready && $stage === ClientStage::Front)
                    <x-ui.badge color="gray" icon="heroicon-m-arrow-right-circle">Not handed over yet</x-ui.badge>
                @endif
                @if ($client->first_sale_at)
                    <span>since {{ $client->first_sale_at->format('j M Y') }}</span>
                @endif
                @if ($sales->count() > 1)
                    <span>· bought {{ $sales->count() }} times</span>
                @endif
            </div>
        </div>
    </header>

    {{-- What you can do. Recording an upsell is the one orange action. --}}
    @if ($can['upsell'] || $can['addFile'] || $care || $can['handOver'] || $can['close'] || $can['reopen'])
        <div class="lq-actions lq-actions-row">
            @if ($can['upsell'])
                <button type="button" class="lq-action lq-action-primary" wire:click="openUpsell">
                    <span class="lq-action-icon"><x-heroicon-o-arrow-trending-up /></span>
                    <span class="lq-action-label">Record an upsell</span>
                </button>
            @endif
            @if ($can['addFile'])
                <button type="button" class="lq-action" wire:click="openUpload">
                    <span class="lq-action-icon"><x-heroicon-o-arrow-up-tray /></span>
                    <span class="lq-action-label">Add files</span>
                </button>
            @endif
            @if ($care)
                <button type="button" class="lq-action" wire:click="openNote">
                    <span class="lq-action-icon"><x-heroicon-o-key /></span>
                    <span class="lq-action-label">Save text</span>
                </button>
            @endif
            @if ($can['handOver'])
                <button type="button" class="lq-action" wire:click="openHandOver">
                    <span class="lq-action-icon"><x-heroicon-o-arrows-right-left /></span>
                    <span class="lq-action-label">{{ $client->isHandedOver() ? 'Give to someone else' : 'Hand over to Upsell' }}</span>
                </button>
            @endif
            @if ($can['close'])
                <button type="button" class="lq-action" wire:click="openClose">
                    <span class="lq-action-icon"><x-heroicon-o-lock-closed /></span>
                    <span class="lq-action-label">Close client</span>
                </button>
            @endif
            @if ($can['reopen'])
                <button type="button" class="lq-action" wire:click="reopen">
                    <span class="lq-action-icon"><x-heroicon-o-lock-open /></span>
                    <span class="lq-action-label">Reopen client</span>
                </button>
            @endif
        </div>
    @endif

    @if ($stage === ClientStage::Closed)
        <div class="lq-note-strip">
            <x-heroicon-o-lock-closed />
            <span>
                Closed on {{ $client->closed_at?->format('j M Y') }}{{ $client->closedBy ? ' by '.$client->closedBy->name : '' }}.
                @if ($client->closed_note) {{ $client->closed_note }} @endif
                Everything is kept; reopen the client to record another sale.
            </span>
        </div>
    @endif

    <div class="lq-split">
        <div class="lq-split-main">
            <x-ui.card flush>
                <div class="lq-viewbar">
                    <div class="lq-views" role="tablist" aria-label="Parts of this client">
                        <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'story']) wire:click="$set('tab', 'story')" role="tab"><x-heroicon-o-map /> Story</button>
                        @if ($ready)
                            <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'files']) wire:click="$set('tab', 'files')" role="tab"><x-heroicon-o-folder-open /> Files @if ($files->isNotEmpty())<span class="lq-view-count">{{ $files->count() }}</span>@endif</button>
                        @endif
                        @if ($care)
                            <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'notes']) wire:click="$set('tab', 'notes')" role="tab"><x-heroicon-o-key /> Saved text @if ($notes->isNotEmpty())<span class="lq-view-count">{{ $notes->count() }}</span>@endif</button>
                        @endif
                        <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'leads']) wire:click="$set('tab', 'leads')" role="tab"><x-heroicon-o-inbox-arrow-down /> Enquiries @if ($leads->isNotEmpty())<span class="lq-view-count">{{ $leads->count() }}</span>@endif</button>
                    </div>
                </div>

                @if ($tab === 'files' && $ready)
                    {{-- The client's storage --}}
                    @if ($files->isEmpty())
                        <x-ui.empty title="No files yet" description="Keep the contract, the invoices and anything else this client sends here. A file may be up to {{ $maxMb }} MB." icon="heroicon-o-folder-open">
                            @if ($can['addFile'])
                                <x-slot:actions><x-ui.button variant="secondary" icon="heroicon-o-arrow-up-tray" wire:click="openUpload">Add files</x-ui.button></x-slot:actions>
                            @endif
                        </x-ui.empty>
                    @else
                        <ul class="lq-files">
                            @foreach ($files as $file)
                                @php $fileUrl = route('brand.clients.files.show', ['brand' => $brand->slug, 'client' => $client, 'file' => $file->getKey()]); @endphp
                                <li class="lq-file" wire:key="file-{{ $file->getKey() }}">
                                    <a class="lq-file-main" href="{{ $fileUrl }}{{ $file->opensInBrowser() ? '?open=1' : '' }}" @if ($file->opensInBrowser()) target="_blank" rel="noopener" @endif title="{{ $file->opensInBrowser() ? 'Open' : 'Download' }} {{ $file->original_name }}">
                                        <span @class(['lq-file-icon', 'lq-file-icon-'.$file->category])>@svg($file->icon())</span>
                                        <span class="min-w-0">
                                            <span class="lq-file-name">{{ $file->original_name }}</span>
                                            <span class="lq-file-meta">{{ $file->categoryLabel() }} · {{ $file->humanSize() }} · {{ $file->uploader?->name ?? 'Someone removed' }} · <span title="{{ $file->created_at?->format('j M Y, H:i') }}">{{ $file->created_at?->diffForHumans() }}</span></span>
                                        </span>
                                    </a>
                                    <div class="lq-row-tools">
                                        <a class="lq-btn lq-btn-ghost lq-btn-icon" href="{{ $fileUrl }}" title="Download" aria-label="Download {{ $file->original_name }}"><x-heroicon-o-arrow-down-tray /></a>
                                        @can('deleteFile', [$client, $file])
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmRemoveFile({{ $file->getKey() }})" title="Remove" aria-label="Remove {{ $file->original_name }}"><x-heroicon-o-trash /></button>
                                        @endcan
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @elseif ($tab === 'notes' && $care)
                    {{-- Text the client shared: logins, links, instructions --}}
                    @if ($notes->isEmpty())
                        <x-ui.empty title="Nothing saved yet" description="Keep what the client shares in words: a login, a link, their instructions. Text marked hidden stays covered until someone clicks Show." icon="heroicon-o-key">
                            <x-slot:actions><x-ui.button variant="secondary" icon="heroicon-o-plus" wire:click="openNote">Save text</x-ui.button></x-slot:actions>
                        </x-ui.empty>
                    @else
                        <div class="lq-vault">
                            @foreach ($notes as $item)
                                @php $shown = ! $item->is_secret || in_array($item->getKey(), $revealed, true); @endphp
                                <article class="lq-vault-item" wire:key="note-{{ $item->getKey() }}">
                                    <header class="lq-vault-head">
                                        <span @class(['lq-file-icon', 'lq-file-icon-secret' => $item->is_secret])>
                                            @if ($item->is_secret) <x-heroicon-o-key /> @else <x-heroicon-o-document-text /> @endif
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="lq-file-name">{{ $item->title }}</span>
                                            <span class="lq-file-meta">{{ $item->author?->name ?? 'Someone removed' }} · <span title="{{ $item->created_at?->format('j M Y, H:i') }}">{{ $item->created_at?->diffForHumans() }}</span>{{ $item->updated_by ? ' · changed '.$item->updated_at?->diffForHumans() : '' }}</span>
                                        </span>
                                        <div class="lq-row-tools">
                                            @if ($item->is_secret)
                                                @if ($shown)
                                                    <x-ui.button size="sm" variant="ghost" icon="heroicon-o-eye-slash" wire:click="hide({{ $item->getKey() }})">Hide</x-ui.button>
                                                @else
                                                    <x-ui.button size="sm" variant="secondary" icon="heroicon-o-eye" wire:click="reveal({{ $item->getKey() }})">Show</x-ui.button>
                                                @endif
                                            @endif
                                            @if ($shown)
                                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" x-data="copy(@js($item->body))" x-on:click="copy()" x-bind:title="copied ? 'Copied' : 'Copy the text'" aria-label="Copy the text">
                                                    <x-heroicon-o-clipboard x-show="! copied" />
                                                    <x-heroicon-o-check x-show="copied" x-cloak />
                                                </button>
                                            @endif
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="openNote({{ $item->getKey() }})" title="Change" aria-label="Change {{ $item->title }}"><x-heroicon-o-pencil-square /></button>
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmRemoveNote({{ $item->getKey() }})" title="Remove" aria-label="Remove {{ $item->title }}"><x-heroicon-o-trash /></button>
                                        </div>
                                    </header>
                                    @if ($shown)
                                        <pre class="lq-vault-body">{{ $item->body }}</pre>
                                    @else
                                        <p class="lq-vault-body lq-vault-covered" aria-label="Hidden text">•••• •••• •••• ••••</p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                        <p class="lq-help px-6 pb-5">The text is stored encrypted. Each time someone shows a hidden text, the log says who and when.</p>
                    @endif
                @elseif ($tab === 'leads')
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
                @else
                    {{-- The story: every sale in order, who made it, and what the client is worth after it. --}}
                    <ol class="lq-story">
                        @foreach ($story as $entry)
                            @if ($entry['type'] === 'sale')
                                @php $sale = $entry['sale']; $upsell = $sale->isUpsell(); @endphp
                                <li @class(['lq-story-item', 'lq-story-upsell' => $upsell, 'lq-story-front' => ! $upsell]) wire:key="sale-{{ $sale->getKey() }}">
                                    <span class="lq-story-node">
                                        @if ($upsell) <x-heroicon-m-arrow-trending-up /> @else <x-heroicon-m-trophy /> @endif
                                    </span>
                                    <div class="lq-story-body">
                                        <div class="lq-story-head">
                                            <span class="lq-story-kind">{{ $upsell ? 'Upsell '.$entry['number'] : 'Front sale' }}</span>
                                            <span class="lq-story-date">{{ $sale->closed_at->format('j M Y') }}</span>
                                            @php $canInvoice = auth()->user()?->can('invoice', $sale) ?? false; @endphp
                                            @if ($can['correct'] || $canInvoice)
                                                <span class="lq-story-tools">
                                                    @if ($canInvoice)
                                                        <button type="button" class="lq-link text-xs" wire:click="openInvoice({{ $sale->getKey() }})">Create invoice</button>
                                                    @endif
                                                    @if ($can['correct'])
                                                        <button type="button" class="lq-link text-xs" wire:click="edit({{ $sale->getKey() }})">Correct</button>
                                                        <button type="button" class="lq-link lq-link-danger text-xs" wire:click="confirmRemove({{ $sale->getKey() }})">Remove</button>
                                                    @endif
                                                </span>
                                            @endif
                                        </div>
                                        <div class="lq-story-row">
                                            <div class="min-w-0">
                                                <div class="lq-story-title">{{ $sale->service ?? ($upsell ? 'Upsell' : 'Sale') }}</div>
                                                <div class="lq-story-who">
                                                    @if ($sale->closer)
                                                        <x-ui.avatar :name="$sale->closer->name" :src="$sale->closer->avatarUrl()" size="xs" />
                                                        <span>{{ $sale->closer->name }}</span>
                                                    @else
                                                        <span>Someone removed</span>
                                                    @endif
                                                    @if ($sale->department)
                                                        <span class="lq-muted">· {{ $sale->department->name }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="lq-story-money">
                                                {{-- The lead behind the sale opens only for someone who may see it (an Upsell team may not see front sales' lead). --}}
                                                @if ($sale->lead_id && ! $upsell && $leads->contains('id', $sale->lead_id))
                                                    <a class="lq-story-amount" href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $sale->lead_id]) }}" title="Open the lead this sale came from" wire:navigate>{{ Money::format($sale->amount, $sale->currency) }}</a>
                                                @else
                                                    <span class="lq-story-amount">{{ Money::format($sale->amount, $sale->currency) }}</span>
                                                @endif
                                                @if ($sales->count() > 1)
                                                    <span class="lq-story-running">{{ Money::format($entry['running'], $sale->currency) }} so far</span>
                                                @endif
                                            </div>
                                        </div>
                                        <x-sales::sale-payments :sale="$sale" :can-pay="auth()->user()?->can('recordPayment', $sale) ?? false" />
                                    </div>
                                </li>
                            @elseif ($entry['type'] === 'handover')
                                <li class="lq-story-item lq-story-step" wire:key="story-handover">
                                    <span class="lq-story-node"><x-heroicon-m-arrows-right-left /></span>
                                    <div class="lq-story-body">
                                        <div class="lq-story-head">
                                            <span class="lq-story-kind">Handed over</span>
                                            <span class="lq-story-date">{{ $entry['at']->format('j M Y') }}</span>
                                        </div>
                                        <div class="lq-story-title">{{ $client->department?->name ?? 'Upsell' }}{{ $client->owner ? ': '.$client->owner->name.' looks after the client' : ': waiting for a person' }}</div>
                                        @if ($client->handedOverBy)
                                            <div class="lq-story-who"><span class="lq-muted">Handed over by {{ $client->handedOverBy->name }}</span></div>
                                        @endif
                                    </div>
                                </li>
                            @elseif ($entry['type'] === 'closed')
                                <li class="lq-story-item lq-story-step" wire:key="story-closed">
                                    <span class="lq-story-node"><x-heroicon-m-lock-closed /></span>
                                    <div class="lq-story-body">
                                        <div class="lq-story-head">
                                            <span class="lq-story-kind">Closed</span>
                                            <span class="lq-story-date">{{ $entry['at']->format('j M Y') }}</span>
                                        </div>
                                        <div class="lq-story-title">{{ $client->closed_note ?: 'The work for this client is over.' }}</div>
                                        @if ($client->closedBy)
                                            <div class="lq-story-who"><span class="lq-muted">Closed by {{ $client->closedBy->name }}</span></div>
                                        @endif
                                    </div>
                                </li>
                            @endif
                        @endforeach

                        @if ($can['upsell'])
                            <li class="lq-story-item lq-story-next">
                                <span class="lq-story-node"><x-heroicon-m-plus /></span>
                                <div class="lq-story-body">
                                    <button type="button" class="lq-story-add" wire:click="openUpsell">Record the next upsell</button>
                                </div>
                            </li>
                        @endif
                    </ol>
                @endif
            </x-ui.card>
        </div>

        <aside class="lq-split-side">
            <section class="lq-stat lq-stat-hero">
                <div class="lq-stat-head">
                    <span class="lq-stat-icon"><x-heroicon-o-banknotes /></span>
                    <span class="lq-stat-label">{{ $care || ! $ready ? 'Lifetime value' : 'Sold by your team' }}</span>
                </div>
                <div class="lq-stat-value-row">
                    <div class="lq-stat-value" x-count>{{ Money::format($totals['all'], $currency) }}</div>
                </div>
                @if ($totals['upsells'] > 0)
                    {{-- How much came from the first sale and how much from upsells. --}}
                    <div class="lq-mix" role="img" aria-label="Front sale {{ $frontShare }} percent, upsells {{ 100 - $frontShare }} percent">
                        <span class="lq-mix-front" style="width: {{ max(3, $frontShare) }}%"></span>
                        <span class="lq-mix-upsell" style="width: {{ max(3, 100 - $frontShare) }}%"></span>
                    </div>
                    <dl class="lq-mix-key">
                        <div><dt><i class="lq-mix-dot lq-mix-front"></i> Front sale</dt><dd class="lq-tabular">{{ Money::format($totals['front'], $currency) }}</dd></div>
                        <div><dt><i class="lq-mix-dot lq-mix-upsell"></i> {{ $totals['upsells'] }} {{ str('upsell')->plural($totals['upsells']) }}</dt><dd class="lq-tabular">{{ Money::format($totals['upsell'], $currency) }}</dd></div>
                    </dl>
                @endif
            </section>

            @if ($ready)
                <section class="lq-card">
                    <div class="lq-card-body">
                        <h2 class="lq-lead-heading lq-lead-heading-row">
                            Looked after by
                            @if ($can['handOver'] && $client->isHandedOver())
                                <button type="button" class="lq-link text-xs" wire:click="openHandOver">Change</button>
                            @endif
                        </h2>

                        @if ($client->owner)
                            <div class="lq-person">
                                <x-ui.avatar :name="$client->owner->name" :src="$client->owner->avatarUrl()" />
                                <span class="min-w-0">
                                    <span class="lq-cell-name">{{ $client->owner->name }}</span>
                                    <span class="lq-cell-sub">{{ $client->owner->designation ?: 'Looks after this client' }}{{ $client->department ? ' · '.$client->department->name : '' }}</span>
                                </span>
                            </div>
                        @elseif ($client->department)
                            <div class="lq-care-empty lq-care-waiting">
                                <x-heroicon-o-clock />
                                <span><strong>{{ $client->department->name }}</strong> has this client. Nobody looks after them yet.</span>
                            </div>
                            @if ($can['assign'])
                                <x-ui.button size="sm" variant="secondary" icon="heroicon-o-user-plus" wire:click="openHandOver" class="mt-3">Choose a person</x-ui.button>
                            @endif
                        @else
                            <div class="lq-care-empty">
                                <x-heroicon-o-arrow-right-circle />
                                <span>Still with front sales. Hand the client to an Upsell team so someone looks after them.</span>
                            </div>
                            @if ($can['handOver'])
                                <x-ui.button size="sm" variant="secondary" icon="heroicon-o-arrows-right-left" wire:click="openHandOver" class="mt-3">Hand over to Upsell</x-ui.button>
                            @endif
                        @endif

                        @if ($client->isHandedOver() && ($client->helpers->isNotEmpty() || $can['share']))
                            <h3 class="lq-lead-heading mt-5">Also on this client</h3>
                            @if ($client->helpers->isEmpty())
                                <p class="lq-help">Add a project manager or a second upseller. Each sees the client and records upsells under their own name.</p>
                            @else
                                <ul class="lq-care-team">
                                    @foreach ($client->helpers as $helper)
                                        <li wire:key="helper-{{ $helper->getKey() }}">
                                            <span class="lq-person min-w-0 flex-1">
                                                <x-ui.avatar :name="$helper->name" :src="$helper->avatarUrl()" size="sm" />
                                                <span class="min-w-0">
                                                    <span class="lq-cell-name">{{ $helper->name }}</span>
                                                    <span class="lq-cell-sub">{{ $helper->designation ?: 'Helps on this client' }}</span>
                                                </span>
                                            </span>
                                            @if ($can['share'])
                                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="removeHelper({{ $helper->getKey() }})" title="Remove from this client" aria-label="Remove {{ $helper->name }} from this client"><x-heroicon-o-x-mark /></button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($can['share'] && $helperOptions !== [])
                                <form wire:submit="addHelper" class="lq-inline-add">
                                    <x-ui.select wire:model="helperId" name="helperId" placeholder="Add a person…" class="lq-input-sm" aria-label="Add a person to this client">
                                        @foreach ($helperOptions as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.button type="submit" size="sm" variant="secondary">Add</x-ui.button>
                                </form>
                                @error('helperId') <p class="lq-error">{{ $message }}</p> @enderror
                            @endif
                        @endif
                    </div>
                </section>
            @endif

            <section class="lq-card">
                <div class="lq-card-body">
                    <dl class="lq-kv">
                        <div><dt>Sales</dt><dd class="lq-tabular">{{ $sales->count() }}</dd></div>
                        @if ($paymentsReady)
                            <div><dt>Still to collect</dt><dd @class(['lq-tabular', 'lq-due-owed' => $totals['due'] > 0])>{{ $totals['due'] > 0 ? Money::format($totals['due'], $currency) : 'Nothing' }}</dd></div>
                        @endif
                        <div><dt>Average sale</dt><dd class="lq-tabular">{{ $sales->count() > 0 ? Money::format(round($totals['all'] / $sales->count()), $currency) : '—' }}</dd></div>
                        <div><dt>First sale</dt><dd>{{ $sales->first()?->closed_at->format('j M Y') ?? '—' }}</dd></div>
                        <div><dt>Last sale</dt><dd title="{{ $sales->last()?->closed_at->format('j M Y') }}">{{ $sales->last()?->closed_at->diffForHumans() ?? '—' }}</dd></div>
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

    {{-- Dialogs --}}
    @if ($can['upsell'])
        @include('sales::partials.payment-sheet')
        @include('sales::partials.invoice-sheet')

        <x-ui.modal model="showUpsell" title="Record an upsell" description="Another sale to this client. It is added to their story and counts towards this month's targets." size="sm">
            <form wire:submit="recordUpsell" class="lq-form" id="upsell-form">
                <x-ui.field label="What was sold" name="upsellService" required>
                    <x-ui.input wire:model="upsellService" name="upsellService" placeholder="Website, domain and hosting…" autofocus />
                </x-ui.field>
                <x-ui.field label="Amount" name="upsellAmount" required>
                    <div class="lq-input-group">
                        <span class="lq-input-prefix">{{ $currency }}</span>
                        <x-ui.input type="number" step="0.01" min="0" wire:model="upsellAmount" name="upsellAmount" inputmode="decimal" placeholder="800" />
                    </div>
                </x-ui.field>
                @if ($paymentsReady)
                    <x-ui.field label="Paid now" name="upsellPaid" help="Only if they paid part of it now. Leave it empty when they paid it all.">
                        <div class="lq-input-group">
                            <span class="lq-input-prefix">{{ $currency }}</span>
                            <x-ui.input type="number" step="0.01" min="0" wire:model="upsellPaid" name="upsellPaid" inputmode="decimal" placeholder="All of it" />
                        </div>
                    </x-ui.field>
                @endif
                <x-ui.field label="Date" name="upsellClosedAt" required>
                    <x-ui.input type="date" wire:model="upsellClosedAt" name="upsellClosedAt" max="{{ now()->toDateString() }}" />
                </x-ui.field>
                @if (count($sellerOptions) > 1)
                    <x-ui.field label="Sold by" name="upsellSeller" help="The upsell goes under this person's name and counts towards their target.">
                        <x-ui.select wire:model="upsellSeller" name="upsellSeller">
                            @foreach ($sellerOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @else
                    <p class="lq-help">The upsell is recorded under your name.</p>
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showUpsell', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="upsell-form" variant="primary" wire:loading.attr="disabled" wire:target="recordUpsell">Record upsell</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($can['handOver'])
        <x-ui.modal model="showHandOver" :title="$client->isHandedOver() ? 'Who looks after this client' : 'Hand over to Upsell'" :description="$client->isHandedOver() ? 'Choose another person, or let the team decide.' : 'The Upsell team takes the client from here: the project, and every sale after the first one.'" size="sm">
            @if ($teams === [])
                <x-ui.empty title="No Upsell department yet" description="Create a department and set what it does to Upsell. Its people can then receive clients." icon="heroicon-o-rectangle-group">
                    <x-slot:actions>
                        <x-ui.button variant="secondary" :href="route('brand.departments.index', ['brand' => $brand->slug])">Open Departments</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty>
            @else
                <form wire:submit="handOver" class="lq-form" id="handover-form">
                    <x-ui.field label="Give the client to" name="handTo" required>
                        <x-ui.select wire:model="handTo" name="handTo" placeholder="Choose a team or a person…">
                            @foreach ($teams as $team)
                                <optgroup label="{{ $team['department']->name }}">
                                    <option value="{{ $team['department']->getKey() }}">{{ $team['department']->name }}: {{ $team['department']->assignment_mode === \App\Modules\Core\Enums\AssignmentMode::RoundRobin ? 'the next person in turn' : 'the team decides' }}</option>
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
                <x-ui.button variant="ghost" wire:click="$set('showHandOver', false)">Cancel</x-ui.button>
                @if ($teams !== [])
                    <x-ui.button type="submit" form="handover-form" variant="primary" wire:loading.attr="disabled" wire:target="handOver">{{ $client->isHandedOver() ? 'Save' : 'Hand over' }}</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($can['addFile'])
        <x-ui.modal model="showUpload" title="Add files" description="Contracts, invoices, documents, pictures: anything this client's work needs. Up to 10 files, {{ $maxMb }} MB each.">
            <form wire:submit="saveFiles" class="lq-form" id="upload-form">
                <x-ui.field label="What are they" name="uploadCategory">
                    <div class="lq-tabs" role="radiogroup" aria-label="Kind of file">
                        @foreach ($categories as $value => $label)
                            <button type="button" role="radio" aria-checked="{{ $uploadCategory === $value ? 'true' : 'false' }}" @class(['lq-tab', 'lq-tab-active' => $uploadCategory === $value]) wire:click="$set('uploadCategory', '{{ $value }}')">{{ $label }}</button>
                        @endforeach
                    </div>
                </x-ui.field>
                <x-ui.field name="uploads">
                    <label class="lq-dropzone" x-data="{ over: false }" x-bind:class="over && 'lq-dropzone-over'" x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false" x-on:drop.prevent="over = false; $refs.files.files = $event.dataTransfer.files; $refs.files.dispatchEvent(new Event('change', { bubbles: true }))">
                        <input type="file" class="sr-only" multiple wire:model="uploads" x-ref="files">
                        <x-heroicon-o-arrow-up-tray />
                        <span class="lq-dropzone-title">Choose files or drop them here</span>
                        <span class="lq-dropzone-sub" wire:loading.remove wire:target="uploads">{{ count($uploads) > 0 ? 'Choose more to add them to the list' : 'PDF, Word, Excel, pictures, zip…' }}</span>
                        <span class="lq-dropzone-sub" wire:loading wire:target="uploads">Uploading…</span>
                    </label>
                    <x-sales::picked-files :files="$uploads" name="uploads" />
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showUpload', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="upload-form" variant="primary" wire:loading.attr="disabled" wire:target="saveFiles, uploads">Add to the client</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($care)
        <x-ui.modal model="showNote" :title="$noteId ? 'Change saved text' : 'Save text'" description="A login the client shared, a link, their instructions. It is stored encrypted and only the people on this client see it.">
            <form wire:submit="saveNote" class="lq-form" id="client-note-form">
                <x-ui.field label="Title" name="noteTitle" required>
                    <x-ui.input wire:model="noteTitle" name="noteTitle" placeholder="Hosting login, Amazon KDP account…" />
                </x-ui.field>
                <x-ui.field label="Text" name="noteBody" required>
                    <x-ui.textarea wire:model="noteBody" name="noteBody" rows="6" placeholder="Address, user name, password, or whatever the client wrote…" class="lq-mono" />
                </x-ui.field>
                <x-ui.field name="noteSecret">
                    <x-ui.checkbox wire:model="noteSecret" name="noteSecret" label="Keep it covered until someone clicks Show (for logins and passwords)" />
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showNote', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="client-note-form" variant="primary" wire:loading.attr="disabled" wire:target="saveNote">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($can['close'])
        <x-ui.modal model="showClose" title="Close this client" description="Use it when the work is over. The client leaves the active list; sales, files and saved text are kept, and the client can be reopened." size="sm">
            <form wire:submit="close" class="lq-form" id="close-form">
                <x-ui.field label="Note" name="closeNote" help="Optional: why, or how it ended.">
                    <x-ui.textarea wire:model="closeNote" name="closeNote" rows="3" placeholder="Project delivered, book published…" />
                </x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="$set('showClose', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="close-form" variant="primary" wire:loading.attr="disabled" wire:target="close">Close client</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($can['correct'])
        <x-ui.modal model="showEdit" title="Correct this sale" description="Targets and this client's lifetime value are counted again. The change is written to the log." size="sm">
            <form wire:submit="update" class="lq-form" id="sale-edit-form">
                <x-ui.field label="Amount" name="amount" required>
                    <div class="lq-input-group">
                        <span class="lq-input-prefix">{{ $currency }}</span>
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
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
