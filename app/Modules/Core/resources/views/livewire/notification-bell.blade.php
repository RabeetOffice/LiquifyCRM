<div class="relative" x-data x-on:click.outside="$wire.open && $wire.set('open', false)" wire:poll.30s>
    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon relative" wire:click="$toggle('open')" title="Notifications" aria-haspopup="menu" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <x-heroicon-o-bell class="h-5 w-5" />
        @if ($unread > 0)
            <span class="lq-count absolute -top-0.5 -right-0.5">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    @if ($open)
        <div class="lq-menu lq-menu-end" style="top: calc(100% + 0.5rem); width: min(22rem, calc(100vw - 2rem));" role="menu">
            <div class="flex items-center justify-between gap-2 px-3 py-2">
                <span class="text-sm font-semibold">Notifications</span>
                @if ($unread > 0)
                    <button type="button" class="lq-link text-xs" wire:click="markAllRead">Mark all read</button>
                @endif
            </div>
            <div class="lq-menu-divider"></div>

            @if ($notifications->isEmpty())
                <p class="px-3 py-6 text-center text-sm lq-muted">Nothing yet. New leads and assignments show up here.</p>
            @else
                <ul class="flex max-h-96 flex-col gap-0.5 overflow-y-auto">
                    @foreach ($notifications as $notification)
                        @php
                            $data = $notification->data;
                            $status = $data['status'] ?? 'info';
                        @endphp
                        <li>
                            <a
                                href="{{ $data['url'] ?? '#' }}"
                                class="lq-menu-item items-start"
                                @if ($notification->read_at === null) wire:click="markRead('{{ $notification->getKey() }}')" @endif
                                wire:navigate
                            >
                                <span @class(['lq-badge mt-0.5', 'lq-badge-'.$status])>
                                    @svg($data['icon'] ?? 'heroicon-o-bell')
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span @class(['block text-sm', 'font-semibold' => $notification->read_at === null])>{{ $data['title'] ?? 'Notification' }}</span>
                                    @if (! empty($data['body']))
                                        <span class="block text-xs lq-muted">{{ $data['body'] }}</span>
                                    @endif
                                    <span class="block text-xs lq-muted">{{ $notification->created_at?->diffForHumans() }}</span>
                                </span>
                                @if ($notification->read_at === null)
                                    <span class="mt-2 h-2 w-2 flex-none rounded-full" style="background: var(--lq-orange)"></span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
