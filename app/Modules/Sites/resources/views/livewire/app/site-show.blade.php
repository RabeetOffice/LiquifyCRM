@php
    // What the mark of a check looks like, by its result.
    $marks = [
        'ok' => 'heroicon-m-check-circle',
        'warn' => 'heroicon-m-exclamation-triangle',
        'fail' => 'heroicon-m-x-circle',
    ];

    $state = $site->health_status->value;

    [$connection, $connectionTone] = match ($site->connectionState()) {
        'connected' => ['Connected', 'ok'],
        'quiet' => ['Quiet for '.(int) config('liquify.sites.quiet_hours', 48).' hours', 'warn'],
        'paused' => ['Paused', 'quiet'],
        default => ['Waiting for the first lead', 'quiet'],
    };
@endphp
<div class="flex flex-col gap-6" data-live-refresh>
    <nav class="lq-crumbbar" aria-label="Breadcrumb">
        <x-ui.button size="sm" variant="ghost" icon="heroicon-o-arrow-left" :href="route('brand.sites.index', ['brand' => $brand->slug])">Websites</x-ui.button>
        <span class="lq-muted" aria-hidden="true">/</span>
        <span class="truncate">{{ $site->name }}</span>
    </nav>

    <header class="lq-lead-hero lq-hero-wrap">
        <x-sites::favicon :site="$site" class="lq-site-icon-xl" />
        <div class="min-w-0 flex-1">
            <h1 class="lq-lead-name lq-lead-name-page">{{ $site->name }}</h1>
            <div class="lq-lead-meta">
                <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="lq-site-domain">{{ $site->domain }} <x-heroicon-m-arrow-top-right-on-square /></a>
                <x-ui.badge :color="$site->platform->getColor()">{{ $site->platform->getLabel() }}</x-ui.badge>
                <span>{{ $site->mode->getLabel() }}</span>
            </div>
        </div>
        @if ($canManage)
            <div class="lq-page-actions">
                @if ($site->isPull())
                    <x-ui.button variant="ghost" icon="heroicon-o-arrow-down-on-square" wire:click="pullNow" wire:loading.attr="disabled" wire:target="pullNow">Read leads now</x-ui.button>
                @else
                    <x-ui.button variant="ghost" icon="heroicon-o-wrench-screwdriver" :href="route('brand.sites.connect', ['brand' => $brand->slug, 'site' => $site])">Setup steps</x-ui.button>
                @endif
                @if ($site->pullDriver() !== null)
                    <x-ui.button variant="ghost" icon="heroicon-o-arrow-down-on-square-stack" wire:click="openImport">Import old leads</x-ui.button>
                @endif
                <x-ui.button variant="primary" icon="heroicon-o-arrow-path" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow">Check now</x-ui.button>
            </div>
        @endif
    </header>

    <div class="lq-minis">
        <x-ui.mini label="Health" :value="$site->health_status->getLabel()" icon="heroicon-o-heart" :tone="in_array($state, ['ok', 'warn', 'fail'], true) ? $state : 'quiet'" />
        <x-ui.mini label="Connection" :value="$connection" icon="heroicon-o-link" :tone="$connectionTone" />
        <x-ui.mini label="Last lead" :value="$site->last_lead_at ? $site->last_lead_at->diffForHumans() : 'None yet'" icon="heroicon-o-inbox-arrow-down" :hint="$site->last_lead_at?->format('j M Y, H:i')" />
        <x-ui.mini label="Last check" :value="$site->last_checked_at ? $site->last_checked_at->diffForHumans() : 'Not checked yet'" icon="heroicon-o-clock" hint="Checks run by themselves every 30 minutes" />
    </div>

    @if ($site->isPull() && $site->last_pull_note)
        <p class="lq-help">Last read {{ $site->last_pulled_at?->diffForHumans() }}: {{ $site->last_pull_note }}</p>
    @endif

    <div class="lq-views lq-views-line" role="tablist">
        <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'health']) wire:click="$set('tab', 'health')" role="tab"><x-heroicon-o-heart /> Health</button>
        <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'leads']) wire:click="$set('tab', 'leads')" role="tab"><x-heroicon-o-inbox-arrow-down /> Received leads @if ($failedCount > 0)<span class="lq-view-count lq-view-count-danger">{{ $failedCount }}</span>@endif</button>
        @if ($canManage)
            <button type="button" @class(['lq-view', 'lq-view-active' => $tab === 'settings']) wire:click="$set('tab', 'settings')" role="tab"><x-heroicon-o-cog-6-tooth /> Settings</button>
        @endif
    </div>

    {{-- Health --}}
    @if ($tab === 'health')
        <div class="lq-probes">
            @foreach ($types as $type)
                @php
                    $check = $latest[$type->value] ?? null;
                    $status = $check?->status ?? \App\Modules\Sites\Enums\CheckStatus::Unknown;
                @endphp
                <article @class(['lq-probe', 'lq-probe-'.$status->value]) wire:key="check-tile-{{ $type->value }}">
                    <header class="lq-probe-head">
                        <span class="lq-probe-icon">@svg($type->getIcon())</span>
                        <h2 class="lq-probe-title">{{ $type->getLabel() }}</h2>
                        <span class="lq-probe-mark" title="{{ $status->getLabel() }}">@svg($marks[$status->value] ?? 'heroicon-m-minus-circle')</span>
                    </header>
                    <p class="lq-probe-status">{{ $status->getLabel() }}</p>
                    <p class="lq-probe-text">{{ $check?->message ?? 'Not checked yet.' }}</p>
                    @if ($check?->response_ms)
                        <span class="lq-probe-time lq-tabular">{{ number_format($check->response_ms) }} ms</span>
                    @endif
                </article>
            @endforeach
        </div>

        @if ($history->isNotEmpty())
            <x-ui.card title="Recent checks" description="The last runs, newest first." flush>
                <div class="lq-table-wrap">
                    <table class="lq-table lq-table-rows">
                        <thead>
                            <tr><th>When</th><th>Check</th><th>Result</th><th>Details</th><th class="lq-table-end">Time</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $row)
                                <tr wire:key="check-{{ $row->getKey() }}">
                                    <td class="lq-muted whitespace-nowrap" title="{{ $row->checked_at->format('j M Y, H:i') }}">{{ $row->checked_at->diffForHumans() }}</td>
                                    <td>{{ $row->type->getLabel() }}</td>
                                    <td><x-ui.badge :color="$row->status->getColor()">{{ $row->status->getLabel() }}</x-ui.badge></td>
                                    <td class="lq-muted">{{ str($row->message)->limit(110) }}</td>
                                    <td class="lq-table-end lq-num">{{ $row->response_ms ? number_format($row->response_ms).' ms' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    @endif

    {{-- Received leads --}}
    @if ($tab === 'leads')
        <x-ui.card flush class="lq-list">
            <div class="lq-viewbar">
                <div class="lq-views" role="tablist" aria-label="Which leads to show">
                    <button type="button" @class(['lq-view', 'lq-view-active' => $eventStatus === '']) wire:click="$set('eventStatus', '')" role="tab">Everything</button>
                    @foreach ($statuses as $status)
                        <button type="button" @class(['lq-view', 'lq-view-active' => $eventStatus === $status->value]) wire:click="$set('eventStatus', '{{ $status->value }}')" role="tab">
                            {{ $status->getLabel() }}
                            @if ($status === \App\Modules\Sites\Enums\InboundStatus::Failed && $failedCount > 0)
                                <span class="lq-view-count lq-view-count-danger">{{ $failedCount }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
                <div class="lq-viewbar-end">
                    @if ($canManage && $failedCount > 0)
                        <x-ui.button size="sm" variant="secondary" icon="heroicon-o-arrow-path" wire:click="retryAllFailed">Retry all {{ $failedCount }} failed</x-ui.button>
                    @endif
                </div>
            </div>

            @if ($events->isEmpty())
                <x-ui.empty title="Nothing received yet" description="Every lead this website sends is listed here, with a Retry button if one could not be processed." icon="heroicon-o-inbox" />
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table lq-table-rows">
                        <thead>
                            <tr><th>Received</th><th>From</th><th>Form</th><th>Result</th><th class="lq-table-end">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($events as $event)
                                @php $fields = (array) ($event->payload['fields'] ?? []); @endphp
                                <tr wire:key="event-{{ $event->getKey() }}">
                                    <td class="lq-muted whitespace-nowrap" title="{{ $event->created_at?->format('j M Y, H:i:s') }}">{{ $event->created_at?->diffForHumans() }}</td>
                                    <td>
                                        @php $mayOpen = $event->lead !== null && ! $event->lead->trashed() && auth()->user()?->can('view', $event->lead); @endphp
                                        @if ($event->isTest())
                                            <span class="lq-muted">Connection test</span>
                                        @else
                                            @if ($event->lead !== null && ! $mayOpen && ! auth()->user()?->isGlobalAdmin())
                                                {{-- A lead of another team: its name is not this person's to see. --}}
                                                <span class="lq-cell-title lq-muted">A lead for another team</span>
                                            @else
                                                <span class="lq-cell-title">{{ $event->lead?->name ?? str((string) ($fields['name'] ?? $fields['Name'] ?? $fields['full_name'] ?? 'Unknown'))->limit(40) }}</span>
                                            @endif
                                            @if ($event->external_id)
                                                <span class="lq-cell-sub">Website row {{ $event->external_id }}</span>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="lq-muted">{{ str((string) ($event->payload['form'] ?? ''))->limit(30) }}</td>
                                    <td>
                                        <x-ui.badge :color="$event->status->getColor()">{{ $event->status->getLabel() }}</x-ui.badge>
                                        @if ($event->error)
                                            <span class="lq-cell-sub">{{ $event->error }}</span>
                                        @endif
                                    </td>
                                    <td class="lq-table-end">
                                        <div class="lq-row-tools">
                                            @if ($mayOpen)
                                                <x-ui.button size="sm" variant="ghost" :href="route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $event->lead_id])">Open lead</x-ui.button>
                                            @endif
                                            @if ($canManage && $event->status !== \App\Modules\Sites\Enums\InboundStatus::Done)
                                                <x-ui.button size="sm" variant="secondary" icon="heroicon-o-arrow-path" wire:click="retry({{ $event->getKey() }})" wire:loading.attr="disabled">Retry</x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $events->links('pagination.lq') }}
            @endif
        </x-ui.card>
    @endif

    {{-- Settings --}}
    @if ($tab === 'settings' && $canManage)
        <form wire:submit="save" class="flex flex-col gap-6">
            <x-ui.card title="Website">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Website name" name="name" required>
                        <x-ui.input wire:model="name" name="name" required />
                    </x-ui.field>
                    <x-ui.field label="Website address" name="url" required>
                        <x-ui.input wire:model="url" name="url" required />
                    </x-ui.field>
                    @unless ($site->usesWebhook())
                        <x-ui.field label="Form handler path" name="formPath" help="The file the form posts to. Used for the form health check." tip="The address of the file that receives the form on the website, for example /form-submission.php or /contact.php. If it is wrong the Form check turns red even though leads arrive.">
                            <x-ui.input wire:model="formPath" name="formPath" placeholder="/form-submission.php" />
                        </x-ui.field>
                    @endunless
                    <x-ui.field name="isActive" help="A paused website is not checked and its leads are refused.">
                        <span class="lq-label">Status</span>
                        <x-ui.checkbox wire:model="isActive" name="isActive" label="Receiving leads" />
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card :title="$site->isPull() ? 'Where to read leads' : 'Health agent (optional)'" :description="$site->isPull() ? 'Stored encrypted. Changing the source starts watching from its newest row.' : 'The email and database checks look from inside the website. They need the LeadsHub agent file (hub-agent.php) on it. Stored encrypted.'">
                @include('sites::livewire.app.partials.pull-fields')
            </x-ui.card>

            <div class="flex items-center justify-end gap-2">
                <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="save">Save changes</x-ui.button>
            </div>
        </form>

        <x-ui.card title="Connection" description="How this website identifies itself to LiquifyCRM.">
            <dl class="lq-dl">
                <div><dt>Site key</dt><dd><code class="lq-code">{{ $site->site_key }}</code></dd></div>
                <div><dt>Leads are sent to</dt><dd><code class="lq-code">{{ $site->ingestUrl() }}</code></dd></div>
                <div><dt>Secret</dt><dd class="lq-muted">Stored encrypted; it is inside the downloaded liquify.php</dd></div>
            </dl>

            <x-slot:footer>
                @unless ($site->isPull() || $site->usesWebhook())
                    <x-ui.button variant="secondary" icon="heroicon-o-arrow-down-tray" :href="route('brand.sites.download', ['brand' => $brand->slug, 'site' => $site])" :navigate="false">Download liquify.php</x-ui.button>
                @endunless
                <x-ui.button variant="ghost" icon="heroicon-o-key" wire:click="confirmNewSecret">Create new secret</x-ui.button>
                <x-ui.button variant="danger" icon="heroicon-o-trash" wire:click="confirmDelete">Remove website</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    @endif

    <x-ui.modal model="showImport" title="Import old leads" description="Reads this website's own leads table once. Original dates are kept, duplicates join the lead that is already here, and each lead is marked Imported." size="sm">
        <form wire:submit="importHistory" class="lq-form" id="import-history-form">
            <x-ui.field label="Received from" name="importFrom" required>
                <x-ui.input type="date" wire:model="importFrom" name="importFrom" />
            </x-ui.field>
            <x-ui.field label="Received until" name="importUntil" required>
                <x-ui.input type="date" wire:model="importUntil" name="importUntil" max="{{ now()->toDateString() }}" />
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="ghost" wire:click="$set('showImport', false)">Cancel</x-ui.button>
            <x-ui.button type="submit" form="import-history-form" variant="secondary" wire:loading.attr="disabled" wire:target="importHistory">Start import</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.confirm :confirm="$confirm" />
</div>
