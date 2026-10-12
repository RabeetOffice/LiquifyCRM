@php
    // What the mark of a check or a website looks like, by its result.
    $marks = [
        'ok' => 'heroicon-m-check-circle',
        'warn' => 'heroicon-m-exclamation-triangle',
        'fail' => 'heroicon-m-x-circle',
    ];

    $healthy = $sites->filter(fn ($site): bool => $site->health_status->value === 'ok')->count();
    $attention = $sites->filter(fn ($site): bool => in_array($site->health_status->value, ['warn', 'fail'], true))->count();
    $lastLead = $sites->max('last_lead_at');
@endphp
<div class="flex flex-col gap-6" data-live-refresh>
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
        <div class="lq-minis">
            <x-ui.mini label="Websites" :value="$sites->count()" icon="heroicon-o-globe-alt" />
            <x-ui.mini label="Healthy" :value="$healthy.' of '.$sites->count()" icon="heroicon-o-heart" :tone="$healthy === $sites->count() ? 'ok' : null" />
            <x-ui.mini label="Need a look" :value="$attention" icon="heroicon-o-exclamation-triangle" :tone="$attention > 0 ? 'warn' : 'quiet'" />
            <x-ui.mini label="Last lead" :value="$lastLead ? $lastLead->diffForHumans() : 'None yet'" icon="heroicon-o-inbox-arrow-down" :hint="$lastLead?->format('j M Y, H:i')" />
        </div>

        <div class="lq-sitegrid">
            @foreach ($sites as $site)
                @php
                    $siteChecks = $checks[$site->getKey()] ?? [];
                    $siteUrl = route('brand.sites.show', ['brand' => $brand->slug, 'site' => $site]);
                    $state = $site->health_status->value;
                @endphp
                <article class="lq-site" wire:key="site-{{ $site->getKey() }}">
                    <header class="lq-site-head">
                        <x-sites::favicon :site="$site" class="lq-site-icon-lg" />
                        <div class="min-w-0 flex-1">
                            <a href="{{ $siteUrl }}" class="lq-site-name" wire:navigate>{{ $site->name }}</a>
                            <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer" class="lq-site-domain">{{ $site->domain }} <x-heroicon-m-arrow-top-right-on-square /></a>
                        </div>
                        <span @class(['lq-state', 'lq-state-'.$state])>
                            <span @class(['lq-health-dot', 'lq-health-'.$state])></span>
                            {{ $site->health_status->getLabel() }}
                        </span>
                    </header>

                    <div class="lq-site-facts">
                        <x-sites::connection :site="$site" />
                        <x-ui.badge :color="$site->platform->getColor()">{{ $site->platform->getLabel() }}</x-ui.badge>
                        <span class="lq-site-note">
                            @if ($site->last_lead_at)
                                Last lead {{ $site->last_lead_at->diffForHumans() }}
                            @elseif ($site->isPull())
                                {{ $site->last_pull_note ?? 'Reads new leads every 5 minutes' }}
                            @else
                                Submit the form once to finish connecting
                            @endif
                        </span>
                    </div>

                    <ul class="lq-checkchips" aria-label="Health checks">
                        @foreach ($types as $type)
                            @php
                                $check = $siteChecks[$type->value] ?? null;
                                $result = $check?->status->value ?? 'unknown';
                            @endphp
                            <li @class(['lq-checkchip', 'lq-checkchip-'.$result]) title="{{ $check?->message ?? 'Not checked yet' }}">
                                @svg($marks[$result] ?? 'heroicon-m-minus-circle')
                                {{ $type->getLabel() }}
                            </li>
                        @endforeach
                    </ul>

                    <footer class="lq-site-foot">
                        <span class="lq-site-note mr-auto">{{ $site->last_checked_at ? 'Checked '.$site->last_checked_at->diffForHumans() : 'Not checked yet' }}</span>
                        @if ($canManage)
                            <x-ui.button size="sm" variant="ghost" icon="heroicon-o-arrow-path" wire:click="checkNow({{ $site->getKey() }})" wire:loading.attr="disabled" wire:target="checkNow({{ $site->getKey() }})">Check now</x-ui.button>
                        @endif
                        <x-ui.button size="sm" variant="secondary" :href="$siteUrl">Open</x-ui.button>
                    </footer>
                </article>
            @endforeach
        </div>
    @endif
</div>
