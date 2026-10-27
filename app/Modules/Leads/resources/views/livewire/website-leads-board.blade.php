<div class="flex flex-col gap-6" data-live-refresh wire:poll.60s.visible>
    <x-ui.page-header title="Website leads" :subtitle="$brands->count() === 1 ? 'The leads that came from the websites of '.$brands->first()->name.'. Read only.' : 'The leads that came from the websites of '.$brands->count().' brands, on one screen. Read only.'" />

    @if ($brands->isEmpty())
        <x-ui.card>
            <x-ui.empty title="No brand chosen for you yet" description="Ask an Admin to choose the brands whose website leads you see (People › Edit)." icon="heroicon-o-globe-alt" />
        </x-ui.card>
    @else
        <div class="lq-minis">
            <x-ui.mini label="Today" :value="number_format($counts['today'])" icon="heroicon-o-sun" :tone="$counts['today'] > 0 ? 'ok' : null" />
            <x-ui.mini label="Last 7 days" :value="number_format($counts['week'])" icon="heroicon-o-calendar-days" />
            <x-ui.mini label="This month" :value="number_format($month)" icon="heroicon-o-chart-bar" />
            <x-ui.mini label="Waiting for a person" :value="number_format($counts['waiting'])" icon="heroicon-o-inbox-arrow-down" :tone="$counts['waiting'] > 0 ? 'warn' : null" hint="Open leads nobody has taken yet" />
        </div>

        <div class="lq-wl-top">
            <x-ui.card title="Leads per day" :description="$perDay['total'] === 0 ? 'The last 30 days, in your time' : number_format($perDay['total']).' '.str('lead')->plural($perDay['total']).' in the last 30 days, in your time'">
                @if ($perDay['total'] === 0)
                    <x-ui.empty title="No website leads in these days" description="A lead sent by one of these brands' websites shows up here at once." icon="heroicon-o-chart-bar" />
                @else
                    <div class="lq-perday">
                        <div class="lq-chart" wire:ignore wire:key="wl-perday-{{ md5(json_encode([$perDay['labels'], $perDay['datasets']])) }}" x-data="chart({
                            type: 'bar',
                            data: { labels: @js($perDay['labels']), datasets: @js($perDay['datasets']) },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: { displayColors: true, boxWidth: 10, boxHeight: 10, boxPadding: 4, filter: (item) => item.raw > 0, itemSort: (a, b) => b.raw - a.raw },
                                },
                                scales: {
                                    x: { stacked: true, grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                                    y: { stacked: true, beginAtZero: true, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } },
                                },
                            },
                        })">
                            <canvas x-ref="canvas"></canvas>
                        </div>

                        <ul class="lq-perday-list" aria-label="Leads per brand">
                            @foreach ($perDay['brands'] as $row)
                                <li wire:key="wl-brand-{{ $row['brand']->getKey() }}">
                                    <button type="button" class="lq-perday-row" wire:click="$set('brandFilter', '{{ $row['brand']->getKey() }}')" title="Show only {{ $row['brand']->name }}">
                                        <span class="lq-perday-dot" style="background: {{ $row['color'] }}"></span>
                                        <span class="lq-perday-name">{{ $row['brand']->name }}</span>
                                        <span class="lq-perday-num">{{ number_format($row['total']) }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card title="Top websites" description="The last 30 days">
                @if ($bySite === [])
                    <p class="lq-help">No website sent a lead in these days.</p>
                @else
                    <ul class="lq-wl-sites">
                        @foreach ($bySite as $row)
                            <li wire:key="wl-site-{{ $row['site'] }}">
                                <button type="button" class="lq-wl-site" wire:click="$set('siteFilter', '{{ $row['site'] }}')" title="Show only {{ $row['name'] }}">
                                    <x-heroicon-o-globe-alt />
                                    <span class="lq-wl-site-name">{{ $row['name'] }}</span>
                                    <span class="lq-wl-site-bar" style="--share: {{ round($row['total'] / max(1, $bySite[0]['total']) * 100) }}%"></span>
                                    <span class="lq-wl-site-num">{{ number_format($row['total']) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card flush class="lq-list">
            <div class="lq-viewbar">
                <div class="lq-views" role="tablist" aria-label="Lead views">
                    @foreach (['all' => 'All', 'today' => 'Today', 'week' => 'Last 7 days', 'waiting' => 'Waiting'] as $key => $label)
                        <button type="button" @class(['lq-view', 'lq-view-active' => $tab === $key]) wire:click="$set('tab', '{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                            {{ $label }} <span class="lq-view-count">{{ number_format($counts[$key]) }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="lq-viewbar-end">
                    @if ($brands->count() > 1)
                        <x-ui.select wire:model.live="brandFilter" placeholder="Every brand" class="lq-input-sm" style="width: 11rem" aria-label="Brand">
                            @foreach ($brands as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                    @if ($sites->count() > 1)
                        <x-ui.select wire:model.live="siteFilter" placeholder="Every website" class="lq-input-sm" style="width: 11rem" aria-label="Website">
                            @foreach ($sites as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                    <label class="lq-search">
                        <x-heroicon-o-magnifying-glass />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, email or phone" aria-label="Search leads">
                    </label>
                </div>
            </div>

            @if ($chips !== [])
                <div class="lq-chips">
                    @foreach ($chips as $key => $label)
                        <button type="button" class="lq-chip" wire:click="removeFilter('{{ $key }}')" wire:key="chip-{{ $key }}" title="Remove this filter">
                            {{ $label }}
                            <x-heroicon-m-x-mark />
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($leads->isEmpty())
                <x-ui.empty :title="$search !== '' || $chips !== [] ? 'No leads match' : 'No website leads yet'" description="Leads from Facebook, Messenger and Instagram are not shown here." :icon="$search !== '' ? 'heroicon-o-magnifying-glass' : 'heroicon-o-globe-alt'" />
            @else
                <div class="lq-table-wrap lq-live">
                    <table class="lq-table lq-table-rows">
                        <thead>
                            <tr>
                                <th>Lead</th>
                                <th>Brand</th>
                                <th class="lq-col-wide">Website</th>
                                <th>Received</th>
                                <th>Status</th>
                                <th class="lq-col-wide">Owner</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($leads as $row)
                                @php $rowBrand = $brandsById[$row->brand_id] ?? null; @endphp
                                <tr wire:key="wl-lead-{{ $row->getKey() }}" data-live-row="{{ $row->getKey() }}" class="lq-row-click" wire:click="openLead({{ $row->getKey() }})" tabindex="0" x-on:keydown.enter="$wire.openLead({{ $row->getKey() }})">
                                    <td class="lq-td-person">
                                        <span class="lq-person">
                                            <x-ui.avatar :name="$row->name" />
                                            <span class="min-w-0">
                                                <span class="lq-cell-title">{{ $row->name }}</span>
                                                <span class="lq-cell-sub">{{ collect([$fullContact ? $row->email : $row->maskedEmail(), $fullContact ? $row->phone : $row->maskedPhone()])->filter()->implode(' · ') ?: 'No contact details' }}</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td>
                                        @if ($rowBrand)
                                            <span class="lq-badge lq-badge-brand">
                                                <x-ui.avatar :name="$rowBrand->name" :src="$rowBrand->avatarUrl()" :color="$rowBrand->primaryColor()" :tile="$rowBrand->tileColor()" size="xs" brand />
                                                {{ $rowBrand->name }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="lq-muted lq-col-wide">{{ $siteNames[$row->source_id] ?? '—' }}</td>
                                    <td class="whitespace-nowrap"><x-ui.received :at="$row->created_at" /></td>
                                    <td>@if ($row->status) <x-ui.status-pill :status="$row->status" /> @endif</td>
                                    <td class="lq-muted lq-col-wide">{{ $row->assignee?->name ?? 'Nobody yet' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $leads->links('pagination.lq') }}
            @endif
        </x-ui.card>
    @endif

    <x-ui.sheet model="showLead" :title="$lead?->name ?? 'Lead'" :description="$lead ? ($lead->brand?->name.($siteNames[$lead->source_id] ?? null ? ' · '.$siteNames[$lead->source_id] : '')) : null">
        @if ($lead !== null)
            <div class="flex flex-col gap-5">
                <dl class="lq-wl-facts">
                    <div><dt>Received</dt><dd><x-ui.received :at="$lead->created_at" inline /></dd></div>
                    <div><dt>Status</dt><dd>@if ($lead->status) <x-ui.status-pill :status="$lead->status" /> @endif</dd></div>
                    <div><dt>Email</dt><dd>{{ ($fullContact ? $lead->email : $lead->maskedEmail()) ?? '—' }}</dd></div>
                    <div><dt>Phone</dt><dd>{{ ($fullContact ? $lead->phone : $lead->maskedPhone()) ?? '—' }}</dd></div>
                    <div><dt>Team</dt><dd>{{ $lead->department?->name ?? 'Not sent to a team yet' }}</dd></div>
                    <div><dt>Owner</dt><dd>{{ $lead->assignee?->name ?? 'Nobody yet' }}</dd></div>
                </dl>

                <section>
                    <h3 class="lq-wl-heading">What they asked for</h3>
                    @if ($answers === [] && blank($lead->message))
                        <p class="lq-help">The form sent no answers.</p>
                    @else
                        @if (filled($lead->message))
                            <p class="lq-wl-message">{{ $lead->message }}</p>
                        @endif
                        @if ($answers !== [])
                            <dl class="lq-wl-facts">
                                @foreach ($answers as $answer)
                                    <div><dt>{{ $answer['label'] }}</dt><dd>{{ $answer['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        @endif
                    @endif
                </section>

                @if ($facts !== [])
                    <section>
                        <h3 class="lq-wl-heading">Where it came from</h3>
                        <dl class="lq-wl-facts">
                            @foreach ($facts as $fact)
                                <div><dt>{{ $fact['label'] }}</dt><dd class="break-all">{{ $fact['value'] }}</dd></div>
                            @endforeach
                        </dl>
                    </section>
                @endif
            </div>
        @endif
    </x-ui.sheet>
</div>
