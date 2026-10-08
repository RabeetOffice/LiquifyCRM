<div class="flex flex-col gap-6">
    <x-ui.page-header title="Websites" subtitle="Every website of this brand: when its last lead arrived and whether its form, email, database, SSL and uptime are healthy.">
        <x-slot:actions>
            @if ($canManage && $sites->isNotEmpty())
                <x-ui.button variant="primary" icon="heroicon-o-plus" :href="route('brand.sites.connect', ['brand' => $brand->slug])">Connect website</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($sites->isEmpty())
        <x-ui.card>
            <x-ui.empty title="No website connected yet" description="Connect a website and every form it receives lands in this brand's New queue within seconds." icon="heroicon-o-globe-alt">
                <x-slot:actions>
                    @if ($canManage)
                        <x-ui.button variant="primary" :href="route('brand.sites.connect', ['brand' => $brand->slug])">Connect your first website</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty>
        </x-ui.card>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            @foreach ($sites as $site)
                @php
                    $siteChecks = $checks[$site->getKey()] ?? [];
                    $siteUrl = route('brand.sites.show', ['brand' => $brand->slug, 'site' => $site]);
                @endphp
                <x-ui.card wire:key="site-{{ $site->getKey() }}">
                    <div class="flex items-start gap-3">
                        <x-sites::favicon :site="$site" />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ $siteUrl }}" class="lq-cell-title" wire:navigate>{{ $site->name }}</a>
                                <x-ui.badge :color="$site->platform->getColor()">{{ $site->platform->getLabel() }}</x-ui.badge>
                            </div>
                            <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="lq-cell-sub lq-link">{{ $site->domain }}</a>
                        </div>
                        <span @class(['lq-health-light', 'lq-health-'.$site->health_status->value]) title="{{ $site->health_status->getLabel() }}"></span>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-sites::connection :site="$site" />
                        <span class="text-sm lq-muted">
                            @if ($site->last_lead_at)
                                Last lead {{ $site->last_lead_at->diffForHumans() }}
                            @elseif ($site->isPull())
                                {{ $site->last_pull_note ?? 'Reads new leads every 5 minutes' }}
                            @else
                                Submit the form once to finish connecting
                            @endif
                        </span>
                    </div>

                    <ul class="lq-health-list mt-4">
                        @foreach ($types as $type)
                            @php $check = $siteChecks[$type->value] ?? null; @endphp
                            <li title="{{ $check?->message ?? 'Not checked yet' }}">
                                <span @class(['lq-health-dot', 'lq-health-'.($check?->status->value ?? 'unknown')])></span>
                                <span>{{ $type->getLabel() }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <x-slot:footer>
                        <span class="mr-auto text-sm lq-muted">{{ $site->last_checked_at ? 'Checked '.$site->last_checked_at->diffForHumans() : 'Not checked yet' }}</span>
                        @if ($canManage)
                            <x-ui.button size="sm" variant="ghost" icon="heroicon-o-arrow-path" wire:click="checkNow({{ $site->getKey() }})" wire:loading.attr="disabled" wire:target="checkNow({{ $site->getKey() }})">Check now</x-ui.button>
                        @endif
                        <x-ui.button size="sm" variant="secondary" :href="$siteUrl">Open</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
