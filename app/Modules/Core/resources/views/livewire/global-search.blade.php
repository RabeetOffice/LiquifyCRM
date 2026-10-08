<div x-data x-on:open-search.window="$wire.open = true">
    <button type="button" class="lq-search-btn" wire:click="$set('open', true)">
        <x-heroicon-o-magnifying-glass class="h-4 w-4" />
        <span class="lq-search-btn-label">Search</span>
        <span class="lq-kbd">Ctrl K</span>
    </button>

    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon sm:hidden" wire:click="$set('open', true)" title="Search">
        <x-heroicon-o-magnifying-glass class="h-5 w-5" />
    </button>

    <x-ui.modal model="open" size="lg">
        <div class="flex flex-col gap-4" x-init="$watch('open', value => value && $nextTick(() => $refs.input.focus()))">
            <div class="relative">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 lq-muted" />
                <input
                    type="search"
                    x-ref="input"
                    wire:model.live.debounce.300ms="query"
                    class="lq-input"
                    style="padding-inline-start: 2.5rem"
                    placeholder="Search leads, brands, departments…"
                    autocomplete="off"
                >
            </div>

            @if ($results === [])
                <p class="lq-help">
                    {{ mb_strlen(trim($query)) < 2 ? 'Type at least two letters.' : 'Nothing found for "'.$query.'".' }}
                </p>
            @else
                <div class="flex flex-col gap-4">
                    @foreach ($results as $group => $items)
                        <div>
                            <div class="lq-side-group-label">{{ $group }}</div>
                            <ul class="flex flex-col gap-0.5">
                                @foreach ($items as $item)
                                    <li>
                                        <a href="{{ $item['url'] }}" class="lq-menu-item" wire:navigate x-on:click="$wire.open = false">
                                            @svg($item['icon'])
                                            <span class="flex-1 truncate">{{ $item['label'] }}</span>
                                            @if ($item['detail'])
                                                <span class="lq-muted text-xs">{{ $item['detail'] }}</span>
                                            @endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-ui.modal>
</div>
