<div class="flex flex-col gap-6">
    <x-ui.page-header title="Integrations" subtitle="Facebook is connected once for all brands. Map each Page to its brand and its leads and Messenger chats go only there.">
        <x-slot:actions>
            @if ($connection)
                <x-ui.button variant="ghost" icon="heroicon-o-arrow-path" wire:click="refreshPages" wire:loading.attr="disabled" wire:target="refreshPages">Refresh Pages</x-ui.button>
            @endif
            @if ($configured && ! $connection)
                <x-ui.button variant="primary" icon="heroicon-o-link" :href="route('integrations.facebook.connect')" :navigate="false">Connect Facebook</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($notice)
        <div @class(['lq-alert', 'lq-alert-'.$notice['type'] => in_array($notice['type'], ['success', 'warning', 'danger'], true)])>
            <x-heroicon-o-information-circle />
            <span>{{ $notice['message'] }}</span>
        </div>
    @endif

    {{-- Setup --}}
    @unless ($configured && $verifyTokenSet && $connection)
        <x-ui.card title="Set up once" description="Three things connect LiquifyCRM to your Meta app. The app secret stays in the .env file on the server and is never shown here.">
            <ol class="lq-steps">
                <li @class(['lq-step', 'lq-step-done' => $configured])>
                    <span class="lq-step-mark">@if ($configured)<x-heroicon-m-check class="h-4 w-4" />@else 1 @endif</span>
                    <div class="lq-step-body">
                        <div class="lq-step-title">Add the Meta app to the .env file</div>
                        <p class="lq-step-desc">Set <code class="lq-code">META_APP_ID</code>, <code class="lq-code">META_APP_SECRET</code> and a <code class="lq-code">META_VERIFY_TOKEN</code> of your choice (any long random text).</p>
                    </div>
                </li>
                <li @class(['lq-step', 'lq-step-done' => $configured && $verifyTokenSet])>
                    <span class="lq-step-mark">2</span>
                    <div class="lq-step-body">
                        <div class="lq-step-title">Tell Meta where to send leads and messages</div>
                        <p class="lq-step-desc">In the Meta app dashboard, under Webhooks, choose Page, and enter this address with your verify token. Under Facebook Login, add the redirect address.</p>
                        <dl class="lq-dl mt-2">
                            <div><dt>Webhook address</dt><dd><code class="lq-code">{{ \App\Modules\Meta\Support\MetaApp::webhookUrl() }}</code></dd></div>
                            <div><dt>Redirect address</dt><dd><code class="lq-code">{{ \App\Modules\Meta\Support\MetaApp::redirectUri() }}</code></dd></div>
                        </dl>
                        @if (! str_starts_with(config('app.url'), 'https://'))
                            <p class="lq-help mt-2">Meta only calls https addresses. On localhost, run a Cloudflare tunnel and set <code class="lq-code">APP_URL</code> to its address (see the README).</p>
                        @endif
                    </div>
                </li>
                <li @class(['lq-step', 'lq-step-done' => (bool) $connection])>
                    <span class="lq-step-mark">3</span>
                    <div class="lq-step-body">
                        <div class="lq-step-title">Connect Facebook</div>
                        <p class="lq-step-desc">Sign in once with a Facebook account that manages your Business Manager; every Page comes in together.</p>
                    </div>
                </li>
            </ol>
        </x-ui.card>
    @endunless

    {{-- Connection --}}
    <x-ui.card title="Facebook connection">
        @if ($connection)
            <dl class="lq-dl">
                <div>
                    <dt>Connected as</dt>
                    <dd class="flex items-center gap-2">
                        {{ $connection->fb_user_name }}
                        <x-ui.badge :color="$connection->error || $connection->isExpired() ? 'danger' : 'success'">{{ $connection->error || $connection->isExpired() ? 'Needs reconnecting' : 'Connected' }}</x-ui.badge>
                    </dd>
                </div>
                <div><dt>Type</dt><dd>{{ $connection->is_system_user ? 'System User token (does not expire)' : 'Facebook login' }}</dd></div>
                @unless ($connection->is_system_user)
                    <div>
                        <dt>Renews by itself until</dt>
                        <dd>
                            {{ $connection->expires_at?->format('j M Y') ?? 'No end date' }}
                            @if ($connection->expires_at && $connection->expiresSoon())
                                <x-ui.badge color="warning">{{ $connection->expires_at->diffForHumans() }}</x-ui.badge>
                            @endif
                        </dd>
                    </div>
                @endunless
                <div><dt>Connected by</dt><dd>{{ $connection->connectedBy?->name ?? '—' }} · {{ $connection->created_at?->diffForHumans() }}</dd></div>
            </dl>
            @if ($connection->error)
                <div class="lq-alert lq-alert-danger mt-4"><x-heroicon-o-exclamation-triangle /><span>{{ $connection->error }}</span></div>
            @endif
        @else
            <x-ui.empty title="Facebook is not connected" :description="$configured ? 'Connect once and every Page of your Business Manager comes in together.' : 'Add the Meta app to the .env file first (step 1 above).'" icon="heroicon-o-link" />
        @endif

        <x-slot:footer>
            @if ($configured)
                <x-ui.button variant="ghost" icon="heroicon-o-key" wire:click="$toggle('showSystemToken')">Use a System User token</x-ui.button>
                @if ($connection)
                    <x-ui.button variant="secondary" icon="heroicon-o-link" :href="route('integrations.facebook.connect')" :navigate="false">Connect Facebook again</x-ui.button>
                    <x-ui.button variant="danger" icon="heroicon-o-x-mark" wire:click="confirmDisconnect">Disconnect</x-ui.button>
                @endif
            @endif
        </x-slot:footer>
    </x-ui.card>

    @if ($showSystemToken)
        <form wire:submit="saveSystemToken">
            <x-ui.card title="System User token" description="For production. Create a System User in Business Manager, give it your Pages and the app, and generate a token that never expires. It is stored encrypted.">
                <x-ui.field label="Token" name="systemToken" required>
                    <x-ui.input type="password" wire:model="systemToken" name="systemToken" autocomplete="off" />
                </x-ui.field>
                <x-slot:footer>
                    <x-ui.button variant="ghost" wire:click="$set('showSystemToken', false)">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="secondary" wire:loading.attr="disabled" wire:target="saveSystemToken">Save token</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>
    @endif

    {{-- Pages --}}
    <x-ui.card title="Facebook Pages" description="Choose the brand each Page belongs to. A Page without a brand keeps its leads waiting." flush>
        @if ($pages->isEmpty())
            <x-ui.empty title="No Pages yet" description="They appear here after Facebook is connected." icon="heroicon-o-flag" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr><th>Page</th><th>Brand</th><th>Leads and messages</th><th>Last received</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($pages as $page)
                            <tr wire:key="page-{{ $page->getKey() }}">
                                <td>
                                    <span class="lq-cell-title">{{ $page->name }}</span>
                                    <span class="lq-cell-sub lq-tabular">Page {{ $page->external_id }}</span>
                                </td>
                                <td>
                                    <select class="lq-input lq-input-sm" style="width: 14rem" wire:change="mapPage({{ $page->getKey() }}, $event.target.value)">
                                        <option value="">Not mapped</option>
                                        @foreach ($brands as $brand)
                                            <option value="{{ $brand->getKey() }}" @selected($page->brand_id === $brand->getKey())>{{ $brand->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    @if ($page->error)
                                        <x-ui.badge color="danger">Problem</x-ui.badge>
                                        <span class="lq-cell-sub">{{ $page->error }}</span>
                                    @elseif ($page->isSubscribed())
                                        <x-ui.badge color="success" icon="heroicon-m-check-circle">Arriving</x-ui.badge>
                                    @else
                                        <x-ui.badge color="gray">Not connected</x-ui.badge>
                                    @endif
                                </td>
                                <td class="lq-muted">{{ $page->last_event_at?->diffForHumans() ?? 'Nothing yet' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Event log --}}
    <x-ui.card title="Received" description="Everything websites and Facebook sent, newest first. Anything that failed can be retried, so no lead is lost." flush>
        <div class="lq-toolbar">
            <select wire:model.live="eventSource" class="lq-input lq-input-sm" style="width: 11rem">
                <option value="">Every source</option>
                @foreach ($sources as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="eventStatus" class="lq-input lq-input-sm" style="width: 11rem">
                <option value="">Every result</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->getLabel() }}</option>
                @endforeach
            </select>
        </div>

        @if ($events->isEmpty())
            <x-ui.empty title="Nothing received yet" description="Website forms, Facebook leads and Messenger messages are listed here as they arrive." icon="heroicon-o-inbox" />
        @else
            <div class="lq-table-wrap">
                <table class="lq-table">
                    <thead>
                        <tr><th>Received</th><th>Source</th><th>Brand</th><th>Result</th><th class="lq-table-end">Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($events as $event)
                            <tr wire:key="event-{{ $event->getKey() }}">
                                <td class="lq-muted whitespace-nowrap" title="{{ $event->created_at?->format('j M Y, H:i:s') }}">{{ $event->created_at?->diffForHumans() }}</td>
                                <td>
                                    <span class="lq-cell-title">{{ $sources[$event->source] ?? $event->source }}</span>
                                    @if ($event->lead)
                                        <span class="lq-cell-sub">{{ $event->lead->name }}</span>
                                    @elseif ($event->isTest())
                                        <span class="lq-cell-sub">Connection test</span>
                                    @endif
                                </td>
                                <td class="lq-muted">{{ $brandNames[$event->brand_id] ?? '—' }}</td>
                                <td>
                                    <x-ui.badge :color="$event->status->getColor()">{{ $event->status->getLabel() }}</x-ui.badge>
                                    @if ($event->error)
                                        <span class="lq-cell-sub">{{ $event->error }}</span>
                                    @endif
                                </td>
                                <td class="lq-table-end">
                                    <div class="flex justify-end gap-1">
                                        @if ($event->lead && ! $event->lead->trashed() && isset($brandSlugs[$event->brand_id]))
                                            <x-ui.button size="sm" variant="ghost" :href="url('/b/'.$brandSlugs[$event->brand_id].'/leads/'.$event->lead_id)">Open lead</x-ui.button>
                                        @endif
                                        @if ($event->status !== \App\Modules\Sites\Enums\InboundStatus::Done)
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

    <x-ui.confirm :confirm="$confirm" />
</div>
