<div x-data x-on:open-search.window="$wire.open = true">
    <button type="button" class="lq-search-btn" wire:click="$set('open', true)">
        <x-heroicon-o-magnifying-glass class="h-4 w-4" />
        <span class="lq-search-btn-label">Search</span>
        <span class="lq-kbd" x-text="navigator.platform.toLowerCase().includes('mac') ? '⌘K' : 'Ctrl K'">Ctrl K</span>
    </button>

    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon sm:hidden" wire:click="$set('open', true)" title="Search">
        <x-heroicon-o-magnifying-glass class="h-5 w-5" />
    </button>

    {{-- The palette is rendered at the end of <body>: the frosted top bar would otherwise trap it. --}}
    <template x-teleport="body">
        <div
            class="lq-modal-backdrop lq-palette"
            x-data="{ open: $wire.entangle('open') }"
            x-show="open"
            x-cloak
            x-on:keydown.escape.window="open = false"
            x-on:click.self="open = false"
            x-init="$watch('open', value => value && $nextTick(() => $refs.input.focus()))"
        >
            <div class="lq-modal" role="dialog" aria-modal="true" aria-label="Search" x-trap.noscroll="open">
                <div class="lq-palette-field">
                    <x-heroicon-o-magnifying-glass />
                    <input
                        type="search"
                        x-ref="input"
                        wire:model.live.debounce.300ms="query"
                        placeholder="Search leads, brands, departments…"
                        autocomplete="off"
                    >
                    <span class="lq-kbd">Esc</span>
                </div>

                @if ($results === [])
                    <p class="lq-palette-hint">
                        {{ mb_strlen(trim($query)) < 2 ? 'Type a name, an email address or a phone number.' : 'Nothing found for "'.$query.'".' }}
                    </p>
                @else
                    <div class="lq-palette-results">
                        @foreach ($results as $group => $items)
                            <div>
                                <div class="lq-side-group-label">{{ $group }}</div>
                                <ul class="flex flex-col gap-0.5">
                                    @foreach ($items as $item)
                                        <li>
                                            <a href="{{ $item['url'] }}" class="lq-menu-item" wire:navigate x-on:click="open = false">
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
        </div>
    </template>
</div>
