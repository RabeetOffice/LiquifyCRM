<div class="flex flex-col gap-6" x-data="{ dragging: null, over: null }">
    <x-ui.page-header title="Pipeline" subtitle="Drag a lead to move it along the pipeline.">
        <x-slot:actions>
            <div class="relative w-64">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 lq-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" class="lq-input lq-input-sm" style="padding-inline-start: 2.25rem" placeholder="Search leads…">
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="lq-board">
        @foreach ($statuses as $status)
            @php $cards = $columns[$status->getKey()]; @endphp
            <section
                class="lq-column"
                :class="{ 'lq-column-over': over === {{ $status->getKey() }} }"
                x-on:dragover.prevent="over = {{ $status->getKey() }}"
                x-on:dragleave="over === {{ $status->getKey() }} && (over = null)"
                x-on:drop.prevent="if (dragging) { $wire.move(dragging, {{ $status->getKey() }}); } dragging = null; over = null"
                wire:key="column-{{ $status->getKey() }}"
            >
                <header class="lq-column-head">
                    <x-ui.status-pill :status="$status" />
                    <span class="lq-pill lq-tabular">{{ $cards->count() }}</span>
                </header>

                @forelse ($cards as $lead)
                    <a
                        href="{{ route('brand.leads.show', ['brand' => $brand->slug, 'lead' => $lead]) }}"
                        class="lq-kcard"
                        draggable="true"
                        x-on:dragstart="dragging = {{ $lead->getKey() }}; $event.dataTransfer.effectAllowed = 'move'"
                        x-on:dragend="dragging = null; over = null"
                        wire:key="card-{{ $lead->getKey() }}"
                        wire:navigate
                    >
                        <div class="lq-kcard-title">{{ $lead->name }}</div>
                        <div class="lq-kcard-meta">
                            <x-ui.badge :color="$lead->source_type->getColor()" :icon="$lead->source_type->getIcon()">{{ $lead->source_type->getLabel() }}</x-ui.badge>
                            @if ($lead->assignee)
                                <span class="flex items-center gap-1"><x-ui.avatar :name="$lead->assignee->name" :src="$lead->assignee->avatarUrl()" size="sm" style="width: 1.25rem; height: 1.25rem; font-size: 0.5rem" /> {{ str($lead->assignee->name)->before(' ') }}</span>
                            @else
                                <span>Unassigned</span>
                            @endif
                            @if ($lead->next_follow_up_at)
                                <span @class(['flex items-center gap-1', 'text-[color:var(--lq-danger)]' => $lead->next_follow_up_at->isPast()])><x-heroicon-o-calendar-days class="h-3.5 w-3.5" /> {{ $lead->next_follow_up_at->format('j M') }}</span>
                            @endif
                        </div>
                    </a>
                @empty
                    <p class="lq-help px-1 py-4 text-center">Nothing here</p>
                @endforelse
            </section>
        @endforeach
    </div>
</div>
