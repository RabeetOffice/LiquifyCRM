@php
    use App\Modules\Meta\Support\MetaApp;
    $healthy = $connection && ! $connection->error && ! $connection->isExpired();
    $unmapped = $pages->whereNull('brand_id')->count();
    // The step the guide is waiting on: 1 keys, 2 addresses, 3 connect.
    $current = ! $configured ? 1 : (! $verifyTokenSet ? 2 : 3);
@endphp
<div class="flex flex-col gap-6" @if ($tab === 'received') wire:poll.20s.visible @endif>
    <x-ui.page-header title="Integrations" subtitle="Where your leads come from. Connect Facebook once for every brand, and connect each brand's websites.">
        <x-slot:actions>
            @if ($tab === 'websites' && $brands->isNotEmpty())
                @if ($brands->count() === 1)
                    <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('brand.sites.connect', ['brand' => $brands->first()->slug])">Connect website</x-ui.button>
                @else
                    <div class="relative" x-data="dropdown" x-on:click.outside="close()" x-on:keydown.escape="close()">
                        <x-ui.button variant="primary" icon="heroicon-o-plus" x-on:click="toggle()">Connect website</x-ui.button>
                        <div class="lq-menu lq-menu-end" style="top: calc(100% + 0.5rem); min-width: 16rem;" x-show="open" x-cloak role="menu">
                            <div class="lq-menu-header">Which brand is this website for?</div>
                            @foreach ($brands as $item)
                                <a href="{{ route('brand.sites.connect', ['brand' => $item->slug]) }}" class="lq-menu-item" role="menuitem" wire:navigate>
                                    <x-ui.avatar :name="$item->name" :src="$item->avatarUrl()" :color="$item->primaryColor()" :tile="$item->tileColor()" size="sm" brand />
                                    <span class="flex-1 truncate">{{ $item->name }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="lq-tabs" role="tablist">
        <button type="button" role="tab" wire:click="$set('tab', 'facebook')" @class(['lq-tab', 'lq-tab-active' => $tab === 'facebook'])>
            <x-ui.brand-icon name="facebook" class="h-4 w-4" /> Facebook
            @if ($connection)
                <span class="lq-live-dot" title="Connected" style="{{ $healthy ? '' : 'background: var(--lq-danger); color: var(--lq-danger)' }}"></span>
            @endif
        </button>
        <button type="button" role="tab" wire:click="$set('tab', 'websites')" @class(['lq-tab', 'lq-tab-active' => $tab === 'websites'])>
            <x-heroicon-o-globe-alt class="h-4 w-4" /> Websites <span class="lq-muted lq-tabular">{{ $siteCount }}</span>
        </button>
        <button type="button" role="tab" wire:click="$set('tab', 'received')" @class(['lq-tab', 'lq-tab-active' => $tab === 'received'])>
            <x-heroicon-o-inbox-arrow-down class="h-4 w-4" /> Received
            @if ($failedCount > 0)
                <x-ui.badge color="danger">{{ $failedCount }} failed</x-ui.badge>
            @endif
        </button>
    </div>

    @if ($notice)
        <div @class(['lq-alert', 'lq-alert-'.$notice['type'] => in_array($notice['type'], ['success', 'warning', 'danger'], true)])>
            <x-heroicon-o-information-circle />
            <span>{{ $notice['message'] }}</span>
        </div>
    @endif

    {{-- ================================================================ Facebook --}}
    @if ($tab === 'facebook')
        @if (! $connection)
            {{-- Not connected yet: only the three steps, nothing else to look at. --}}
            <x-ui.card>
                <div class="lq-integration">
                    <span class="lq-logo-tile lq-logo-facebook"><x-ui.brand-icon name="facebook" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="lq-card-title">Facebook Lead Ads and Messenger</h2>
                        <p class="lq-card-desc">Three steps, done once. After that every lead form and every message to your Pages lands in the right brand by itself.</p>
                    </div>
                    <x-ui.badge color="gray">Not connected</x-ui.badge>
                </div>

                <div class="lq-divider my-6"></div>

                <ol class="lq-guide">
                    <li @class(['lq-guide-step', 'lq-guide-done' => $configured, 'lq-guide-current' => $current === 1])>
                        <span class="lq-guide-mark">@if ($configured)<x-heroicon-m-check />@else 1 @endif</span>
                        <div>
                            <h3 class="lq-guide-title">Add your Meta app to the server</h3>
                            @if ($configured)
                                <p class="lq-guide-text">Done. The app id and secret are in the server's <code class="lq-code">.env</code> file and are never shown here.</p>
                            @else
                                <p class="lq-guide-text">Put <code class="lq-code">META_APP_ID</code>, <code class="lq-code">META_APP_SECRET</code> and a <code class="lq-code">META_VERIFY_TOKEN</code> (any long random text) in the server's <code class="lq-code">.env</code> file, then reload this page.</p>
                            @endif
                        </div>
                    </li>

                    <li @class(['lq-guide-step', 'lq-guide-done' => $configured && $verifyTokenSet && $current > 2, 'lq-guide-current' => $current === 2, 'lq-guide-later' => $current < 2])>
                        <span class="lq-guide-mark">2</span>
                        <div>
                            <h3 class="lq-guide-title">Tell Meta where to send leads and messages</h3>
                            <p class="lq-guide-text">In the Meta app dashboard, paste the first address under <strong>Webhooks &rsaquo; Page</strong> with your verify token, and the second under <strong>Facebook Login &rsaquo; Valid OAuth Redirect URIs</strong>.</p>
                            @if ($configured)
                                <div class="lq-guide-body">
                                    @foreach (['Webhook address' => MetaApp::webhookUrl(), 'Redirect address' => MetaApp::redirectUri()] as $label => $value)
                                        <div class="lq-copyrow" x-data="copy(@js($value))">
                                            <span class="lq-copyrow-label">{{ $label }}</span>
                                            <span class="lq-copyrow-value" title="{{ $value }}">{{ $value }}</span>
                                            <button type="button" class="lq-btn lq-btn-secondary lq-btn-sm" x-on:click="copy()">
                                                <x-heroicon-o-clipboard-document x-show="! copied" />
                                                <x-heroicon-o-check x-show="copied" x-cloak />
                                                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                            </button>
                                        </div>
                                    @endforeach
                                    @if (! str_starts_with((string) config('app.url'), 'https://'))
                                        <p class="lq-help">Meta only calls https addresses. On a local machine, run a tunnel and set <code class="lq-code">APP_URL</code> to its address.</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </li>

                    <li @class(['lq-guide-step', 'lq-guide-current' => $current === 3, 'lq-guide-later' => $current < 3])>
                        <span class="lq-guide-mark">3</span>
                        <div>
                            <h3 class="lq-guide-title">Connect Facebook</h3>
                            <p class="lq-guide-text">Sign in once with the Facebook account that manages your Pages. Every Page comes in together and you choose its brand next.</p>
                            <div class="lq-guide-body">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($configured)
                                        <x-ui.button variant="primary" :href="route('integrations.facebook.connect')" :navigate="false">
                                            <x-ui.brand-icon name="facebook" /> Connect Facebook
                                        </x-ui.button>
                                        <x-ui.button variant="ghost" icon="heroicon-o-key" wire:click="$toggle('showSystemToken')">Use a System User token instead</x-ui.button>
                                    @else
                                        <x-ui.button variant="primary" disabled>
                                            <x-ui.brand-icon name="facebook" /> Connect Facebook
                                        </x-ui.button>
                                        <span class="lq-help">Finish step 1 first.</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>
            </x-ui.card>
        @else
            {{-- Connected: the connection, then the Pages to map. --}}
            <x-ui.card>
                <div class="lq-integration">
                    <span class="lq-logo-tile lq-logo-facebook"><x-ui.brand-icon name="facebook" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 class="lq-card-title">Facebook Lead Ads and Messenger</h2>
                        <p class="lq-card-desc">
                            Connected as <strong class="lq-ink">{{ $connection->fb_user_name }}</strong>
                            by {{ $connection->connectedBy?->name ?? 'someone' }} {{ $connection->created_at?->diffForHumans() }}.
                            {{ $connection->is_system_user ? 'System User token, does not expire.' : ($connection->expires_at ? 'Renews by itself until '.$connection->expires_at->format('j M Y').'.' : 'Renews by itself.') }}
                        </p>
                    </div>
                    <x-ui.badge :color="$healthy ? 'success' : 'danger'" :icon="$healthy ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'">{{ $healthy ? 'Connected' : 'Needs reconnecting' }}</x-ui.badge>
                </div>

                @if ($connection->error)
                    <div class="lq-alert lq-alert-danger mt-5"><x-heroicon-o-exclamation-triangle /><span>{{ $connection->error }}</span></div>
                @elseif ($connection->expires_at && $connection->expiresSoon())
                    <div class="lq-alert lq-alert-warning mt-5"><x-heroicon-o-clock /><span>The connection ends {{ $connection->expires_at->diffForHumans() }}. Click Connect again to renew it.</span></div>
                @endif

                <x-slot:footer>
                    <x-ui.button variant="ghost" icon="heroicon-o-key" wire:click="$toggle('showSystemToken')" class="mr-auto">Use a System User token</x-ui.button>
                    <x-ui.button variant="ghost" icon="heroicon-o-arrow-path" wire:click="refreshPages" wire:loading.attr="disabled" wire:target="refreshPages">Refresh Pages</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('integrations.facebook.connect')" :navigate="false">Connect again</x-ui.button>
                    <x-ui.button variant="danger" icon="heroicon-o-x-mark" wire:click="confirmDisconnect">Disconnect</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        @endif

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

        @if ($connection)
            @if ($brands->isEmpty())
                <div class="lq-alert lq-alert-warning">
                    <x-heroicon-o-building-storefront />
                    <span>Add a brand first: a Page needs a brand to send its leads to. <a class="lq-link" href="{{ route('admin.brands.create') }}" wire:navigate>Add brand</a></span>
                </div>
            @elseif ($unmapped > 0)
                <div class="lq-alert lq-alert-warning">
                    <x-heroicon-o-exclamation-triangle />
                    <span>{{ $unmapped }} {{ str('Page')->plural($unmapped) }} {{ $unmapped === 1 ? 'has' : 'have' }} no brand yet. Leads and messages from {{ $unmapped === 1 ? 'it' : 'them' }} wait until you choose one below.</span>
                </div>
            @endif

            <x-ui.card title="Your Pages" description="Choose the brand each Page belongs to. Its leads and Messenger chats go only there." flush>
                @if ($pages->isEmpty())
                    <x-ui.empty title="No Pages found" description="The Facebook account has no Pages it manages, or none were shared. Click Refresh Pages after giving it access." icon="heroicon-o-flag" />
                @else
                    <div class="lq-table-wrap">
                        <table class="lq-table">
                            <thead>
                                <tr><th>Page</th><th>Sends leads to</th><th>Status</th><th>Last received</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($pages as $page)
                                    <tr wire:key="page-{{ $page->getKey() }}">
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <span class="lq-logo-tile lq-logo-sm lq-logo-facebook"><x-ui.brand-icon name="facebook" /></span>
                                                <div class="min-w-0">
                                                    <span class="lq-cell-title">{{ $page->name }}</span>
                                                    <span class="lq-cell-sub lq-tabular">Page {{ $page->external_id }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <select class="lq-input lq-input-sm" style="width: 15rem" wire:change="mapPage({{ $page->getKey() }}, $event.target.value)" aria-label="Brand for {{ $page->name }}">
                                                <option value="">Choose a brand…</option>
                                                @foreach ($brands as $brand)
                                                    <option value="{{ $brand->getKey() }}" @selected($page->brand_id === $brand->getKey())>{{ $brand->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            @if ($page->error)
                                                <x-ui.badge color="danger">Problem</x-ui.badge>
                                                <span class="lq-cell-sub">{{ $page->error }}</span>
                                            @elseif (! $page->brand_id)
                                                <x-ui.badge color="warning" icon="heroicon-m-clock">Waiting for a brand</x-ui.badge>
                                            @elseif ($page->isSubscribed())
                                                <x-ui.badge color="success" icon="heroicon-m-check-circle">Leads arriving</x-ui.badge>
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
        @endif
    @endif

    {{-- ================================================================ Websites --}}
    @if ($tab === 'websites')
        <x-ui.card flush>
            @if ($sites->isEmpty())
                <x-ui.empty title="No website connected yet" description="Connect a website and every form it receives lands in its brand's New queue within seconds." icon="heroicon-o-globe-alt">
                    <x-slot:actions>
                        @if ($brands->isEmpty())
                            <x-ui.button variant="secondary" :href="route('admin.brands.create')">Add a brand first</x-ui.button>
                        @else
                            <span class="lq-help">Use “Connect website” above and choose the brand.</span>
                        @endif
                    </x-slot:actions>
                </x-ui.empty>
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table">
                        <thead>
                            <tr><th>Website</th><th>Brand</th><th>Connection</th><th>Last lead</th><th>Health</th><th class="lq-table-end">Actions</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($sites as $site)
                                @php $siteBrand = $site->brand; @endphp
                                <tr wire:key="site-{{ $site->getKey() }}">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <x-sites::favicon :site="$site" />
                                            <div class="min-w-0">
                                                <span class="lq-cell-title">{{ $site->name }}</span>
                                                <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="lq-cell-sub lq-link">{{ $site->domain }}</a>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        @if ($siteBrand)
                                            <span class="flex items-center gap-2">
                                                <x-ui.avatar :name="$siteBrand->name" :src="$siteBrand->avatarUrl()" :color="$siteBrand->primaryColor()" :tile="$siteBrand->tileColor()" size="sm" brand />
                                                <span class="truncate">{{ $siteBrand->name }}</span>
                                            </span>
                                        @else
                                            <span class="lq-muted">—</span>
                                        @endif
                                    </td>
                                    <td><x-sites::connection :site="$site" /></td>
                                    <td class="lq-muted whitespace-nowrap">{{ $site->last_lead_at?->diffForHumans() ?? 'None yet' }}</td>
                                    <td>
                                        <span class="flex items-center gap-2">
                                            <span @class(['lq-health-light', 'lq-health-'.$site->health_status->value])></span>
                                            <span class="text-sm">{{ $site->health_status->getLabel() }}</span>
                                        </span>
                                    </td>
                                    <td class="lq-table-end">
                                        @if ($siteBrand)
                                            <x-ui.button size="sm" variant="secondary" :href="route('brand.sites.show', ['brand' => $siteBrand->slug, 'site' => $site])">Open</x-ui.button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- ================================================================ Received --}}
    @if ($tab === 'received')
        <x-ui.card flush>
            <x-slot:actions>
                <span class="flex items-center gap-2 text-sm lq-muted"><span class="lq-live-dot"></span> Updates by itself</span>
            </x-slot:actions>
            <div class="lq-toolbar">
                <select wire:model.live="eventSource" class="lq-input lq-input-sm" style="width: 11rem" aria-label="Source">
                    <option value="">Every source</option>
                    @foreach ($sources as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="eventStatus" class="lq-input lq-input-sm" style="width: 11rem" aria-label="Result">
                    <option value="">Every result</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->getLabel() }}</option>
                    @endforeach
                </select>
            </div>

            @if ($events->isEmpty())
                <x-ui.empty title="Nothing received yet" description="Website forms, Facebook leads and Messenger messages are listed here as they arrive. Anything that fails can be retried, so no lead is lost." icon="heroicon-o-inbox" />
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table">
                        <thead>
                            <tr><th>Received</th><th>Source</th><th>Brand</th><th>Result</th><th class="lq-table-end">Actions</th></tr>
                        </thead>
                        <tbody class="lq-live">
                            @foreach ($events as $event)
                                <tr wire:key="event-{{ $event->getKey() }}">
                                    <td class="lq-muted whitespace-nowrap" title="{{ $event->created_at?->format('j M Y, H:i:s') }}">{{ $event->created_at?->diffForHumans() }}</td>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            @if ($event->source === 'site')
                                                <span class="lq-logo-tile lq-logo-sm lq-logo-web"><x-heroicon-o-globe-alt /></span>
                                            @elseif ($event->source === \App\Modules\Meta\Jobs\ProcessMetaEvent::MESSAGE)
                                                <span class="lq-logo-tile lq-logo-sm lq-logo-messenger"><x-ui.brand-icon name="messenger" /></span>
                                            @else
                                                <span class="lq-logo-tile lq-logo-sm lq-logo-facebook"><x-ui.brand-icon name="facebook" /></span>
                                            @endif
                                            <div class="min-w-0">
                                                <span class="lq-cell-title">{{ $sources[$event->source] ?? $event->source }}</span>
                                                @if ($event->lead)
                                                    <span class="lq-cell-sub">{{ $event->lead->name }}</span>
                                                @elseif ($event->isTest())
                                                    <span class="lq-cell-sub">Connection test</span>
                                                @endif
                                            </div>
                                        </div>
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
    @endif

    <x-ui.confirm :confirm="$confirm" />
</div>
