{{-- The board refreshes by itself every 30 seconds, except while a card is being dragged. --}}
<div
    class="lq-wide flex flex-col gap-5"
    x-data="{
        dragging: null,
        over: null,
        timer: null,
        init() { this.timer = setInterval(() => { if (this.dragging === null && ! document.hidden) this.$wire.$refresh() }, 30000) },
        destroy() { clearInterval(this.timer) },
    }"
>
    <x-ui.page-header title="Pipeline" subtitle="Drag a card to move a lead along. Click one to open it.">
        <x-slot:actions>
            @unless ($agent)
                <x-ui.select wire:model.live="owner" class="lq-input-sm" style="width: 12rem" aria-label="Show leads of">
                    <option value="">Everyone's leads</option>
                    <option value="none">Unassigned</option>
                    @foreach ($agentOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            @endunless
            <label class="lq-search">
                <x-heroicon-o-magnifying-glass />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search leads" aria-label="Search leads">
            </label>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="lq-boardbar">
        <span class="lq-boardbar-item"><strong>{{ $summary['open'] }}</strong> open {{ str('lead')->plural($summary['open']) }}</span>
        @unless ($agent)
            <a href="{{ route('brand.leads.index', ['brand' => $brand->slug, 'tab' => 'unassigned']) }}" @class(['lq-boardbar-item', 'lq-boardbar-warn' => $summary['unassigned'] > 0]) wire:navigate>
                <x-heroicon-m-user /> <strong>{{ $summary['unassigned'] }}</strong> unassigned
            </a>
        @endunless
        <a href="{{ route('brand.leads.index', ['brand' => $brand->slug, 'tab' => 'follow-ups']) }}" @class(['lq-boardbar-item', 'lq-boardbar-late' => $summary['overdue'] > 0]) wire:navigate>
            <x-heroicon-m-exclamation-circle /> <strong>{{ $summary['overdue'] }}</strong> {{ $summary['overdue'] === 1 ? 'follow-up' : 'follow-ups' }} overdue
        </a>
        @if ($quiet !== [])
            <span class="lq-boardbar-item" title="Open leads with no activity for {{ \App\Modules\Leads\Livewire\App\Pipeline::QUIET_AFTER_DAYS }} days or more"><span class="lq-quiet-dot"></span> <strong>{{ count($quiet) }}</strong> gone quiet</span>
        @endif
    </div>

    <div class="lq-board">
        @foreach ($statuses as $status)
            @php $cards = $columns[$status->getKey()]; @endphp
            <section
                class="lq-column"
                style="--pill-color: {{ $status->color }}"
                :class="{ 'lq-column-over': over === {{ $status->getKey() }} }"
                x-on:dragover.prevent="over = {{ $status->getKey() }}"
                x-on:dragleave="over === {{ $status->getKey() }} && (over = null)"
                x-on:drop.prevent="if (dragging) { $wire.move(dragging, {{ $status->getKey() }}); } dragging = null; over = null"
                wire:key="column-{{ $status->getKey() }}"
            >
                <header class="lq-column-head">
                    <span class="lq-column-dot"></span>
                    <h2 class="lq-column-title">{{ $status->name }}</h2>
                    <span class="lq-column-count">{{ $cards->count() }}</span>
                    @if ($status->is_won && $wonTotal)
                        <span class="lq-column-sum" title="Sales recorded on these leads">{{ $wonTotal }}</span>
                    @endif
                </header>

                <div class="lq-column-body">
                    @forelse ($cards as $lead)
                        <a
                            href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $lead]) }}"
                            class="lq-kcard"
                            :class="{ 'lq-kcard-dragging': dragging === {{ $lead->getKey() }} }"
                            draggable="true"
                            x-on:dragstart="dragging = {{ $lead->getKey() }}; $event.dataTransfer.effectAllowed = 'move'"
                            x-on:dragend="dragging = null; over = null"
                            x-on:click="if (! $event.ctrlKey && ! $event.metaKey) { $event.preventDefault(); $wire.open = {{ $lead->getKey() }}; $wire.openLead({{ $lead->getKey() }}) }"
                            wire:key="card-{{ $lead->getKey() }}"
                        >
                            <div class="lq-kcard-top">
                                <x-ui.avatar :name="$lead->name" size="sm" tone />
                                <span class="lq-kcard-title">{{ $lead->name }}</span>
                                @if (in_array($lead->getKey(), $quiet, true))
                                    <span class="lq-quiet-dot" title="No activity since {{ $lead->last_activity_at?->format('j M') }}"></span>
                                @endif
                            </div>
                            @if (filled($lead->fields['service'] ?? null))
                                <div class="lq-kcard-note">{{ $lead->fields['service'] }}</div>
                            @endif
                            <div class="lq-kcard-meta">
                                <x-ui.lead-source :lead="$lead" />
                                @if ($lead->next_follow_up_at)
                                    <x-ui.due :at="$lead->next_follow_up_at" :time="false" />
                                @endif
                            </div>
                            <div class="lq-kcard-foot">
                                @if ($lead->assignee)
                                    <span class="lq-person"><x-ui.avatar :name="$lead->assignee->name" :src="$lead->assignee->avatarUrl()" size="xs" /> {{ str($lead->assignee->name)->before(' ') }}</span>
                                @else
                                    <span class="lq-unassigned">Unassigned</span>
                                @endif
                                <span class="lq-kcard-age" title="Last activity {{ $lead->last_activity_at?->format('j M Y, H:i') }}">{{ $lead->last_activity_at?->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
                            </div>
                        </a>
                    @empty
                        <p class="lq-column-empty">No leads here</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    @include('leads::livewire.app.partials.lead-sheet')
</div>
