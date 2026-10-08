<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$site->name" :subtitle="$site->domain" :breadcrumbs="['Websites' => route('brand.sites.index', ['brand' => $brand->slug]), $site->name => null]">
        <x-slot:actions>
            @if ($canManage)
                @if ($site->isPull())
                    <x-ui.button variant="ghost" icon="heroicon-o-arrow-down-on-square" wire:click="pullNow" wire:loading.attr="disabled" wire:target="pullNow">Read leads now</x-ui.button>
                @else
                    <x-ui.button variant="ghost" icon="heroicon-o-wrench-screwdriver" :href="route('brand.sites.connect', ['brand' => $brand->slug, 'site' => $site])">Setup steps</x-ui.button>
                @endif
                <x-ui.button variant="primary" icon="heroicon-o-arrow-path" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow">Check now</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
            <div class="flex items-center gap-3">
                <x-sites::favicon :site="$site" />
                <div>
                    <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="lq-cell-title lq-link">{{ $site->domain }}</a>
                    <span class="lq-cell-sub">{{ $site->mode->getLabel() }}</span>
                </div>
            </div>
            <x-ui.badge :color="$site->platform->getColor()">{{ $site->platform->getLabel() }}</x-ui.badge>
            <x-sites::connection :site="$site" />
            <span class="text-sm lq-muted">{{ $site->last_lead_at ? 'Last lead '.$site->last_lead_at->diffForHumans() : 'No lead received yet' }}</span>
            <span class="ml-auto flex items-center gap-2 text-sm">
                <span @class(['lq-health-light', 'lq-health-'.$site->health_status->value])></span>
                {{ $site->health_status->getLabel() }}
            </span>
        </div>
        @if ($site->isPull() && $site->last_pull_note)
            <p class="lq-help mt-3">Last read {{ $site->last_pulled_at?->diffForHumans() }}: {{ $site->last_pull_note }}</p>
        @endif
    </x-ui.card>

    <div class="lq-tabs" role="tablist">
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'health']) wire:click="$set('tab', 'health')" role="tab"><x-heroicon-o-heart class="h-4 w-4" /> Health</button>
        <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'leads']) wire:click="$set('tab', 'leads')" role="tab"><x-heroicon-o-inbox-arrow-down class="h-4 w-4" /> Received leads @if ($failedCount > 0)<span class="lq-count">{{ $failedCount }}</span>@endif</button>
        @if ($canManage)
            <button type="button" @class(['lq-tab', 'lq-tab-active' => $tab === 'settings']) wire:click="$set('tab', 'settings')" role="tab"><x-heroicon-o-cog-6-tooth class="h-4 w-4" /> Settings</button>
        @endif
    </div>

    {{-- Health --}}
    @if ($tab === 'health')
        <x-ui.card title="Health checks" :description="$site->last_checked_at ? 'Checked '.$site->last_checked_at->diffForHumans().'. Checks run by themselves every 30 minutes.' : 'Not checked yet. Checks run by themselves every 30 minutes.'" flush>
            <ul class="flex flex-col">
                @foreach ($types as $type)
                    @php $check = $latest[$type->value] ?? null; @endphp
                    <li class="flex items-start gap-3 px-6 py-4" style="border-top: 1px solid var(--lq-hairline)">
                        <span @class(['lq-health-dot', 'lq-health-'.($check?->status->value ?? 'unknown')]) style="margin-top: 0.4rem"></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="lq-cell-title">{{ $type->getLabel() }}</span>
                                <x-ui.badge :color="($check?->status ?? \App\Modules\Sites\Enums\CheckStatus::Unknown)->getColor()">{{ ($check?->status ?? \App\Modules\Sites\Enums\CheckStatus::Unknown)->getLabel() }}</x-ui.badge>
                            </div>
                            <p class="text-sm lq-muted">{{ $check?->message ?? 'Not checked yet.' }}</p>
                        </div>
                        @if ($check?->response_ms)
                            <span class="lq-pill lq-tabular">{{ number_format($check->response_ms) }} ms</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        @if ($history->isNotEmpty())
            <x-ui.card title="Recent checks" flush>
                <div class="lq-table-wrap">
                    <table class="lq-table">
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
        <x-ui.card flush>
            <div class="lq-toolbar">
                <select wire:model.live="eventStatus" class="lq-input lq-input-sm" style="width: 11rem">
                    <option value="">Everything received</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->getLabel() }}</option>
                    @endforeach
                </select>
                <div class="lq-toolbar-end">
                    @if ($canManage && $failedCount > 0)
                        <x-ui.button size="sm" variant="secondary" icon="heroicon-o-arrow-path" wire:click="retryAllFailed">Retry all {{ $failedCount }} failed</x-ui.button>
                    @endif
                </div>
            </div>

            @if ($events->isEmpty())
                <x-ui.empty title="Nothing received yet" description="Every lead this website sends is listed here, with a Retry button if one could not be processed." icon="heroicon-o-inbox" />
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table">
                        <thead>
                            <tr><th>Received</th><th>From</th><th>Form</th><th>Result</th><th class="lq-table-end">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($events as $event)
                                @php $fields = (array) ($event->payload['fields'] ?? []); @endphp
                                <tr wire:key="event-{{ $event->getKey() }}">
                                    <td class="lq-muted whitespace-nowrap" title="{{ $event->created_at?->format('j M Y, H:i:s') }}">{{ $event->created_at?->diffForHumans() }}</td>
                                    <td>
                                        @if ($event->isTest())
                                            <span class="lq-muted">Connection test</span>
                                        @else
                                            <span class="lq-cell-title">{{ $event->lead?->name ?? str((string) ($fields['name'] ?? $fields['Name'] ?? $fields['full_name'] ?? 'Unknown'))->limit(40) }}</span>
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
                                        <div class="flex justify-end gap-1">
                                            @if ($event->lead && ! $event->lead->trashed())
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
                        <x-ui.field label="Form handler path" name="formPath" help="The file the form posts to. Used for the form health check.">
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

    <x-ui.confirm :confirm="$confirm" />
</div>
