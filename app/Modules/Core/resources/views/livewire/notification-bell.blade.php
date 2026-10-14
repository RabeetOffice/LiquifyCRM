<div class="relative" x-data x-on:click.outside="$wire.open && $wire.set('open', false)" x-on:lq-notified.window="$wire.tick()" wire:poll.60s="tick">
    <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon relative" wire:click="$toggle('open')" title="Notifications" aria-haspopup="menu" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <x-heroicon-o-bell class="h-5 w-5" />
        @if ($unread > 0)
            <span class="lq-count absolute -top-0.5 -right-0.5" wire:key="count-{{ $unread }}">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    @if ($open)
        <div class="lq-menu lq-menu-end" style="top: calc(100% + 0.5rem); width: min(24rem, calc(100vw - 2rem)); padding: 0;" role="menu">
            <div class="flex items-center justify-between gap-2 px-4 py-3">
                <span class="flex items-center gap-2 text-sm font-semibold">
                    Notifications
                    <span class="lq-live-dot" title="Updates by itself"></span>
                </span>
                @if ($unread > 0)
                    <button type="button" class="lq-link text-xs" wire:click="markAllRead">Mark all read</button>
                @endif
            </div>
            <div class="lq-menu-divider" style="margin: 0"></div>

            @if ($notifications->isEmpty())
                <div class="lq-empty" style="padding: 2rem 1.5rem">
                    <span class="lq-empty-icon"><x-heroicon-o-bell /></span>
                    <p class="lq-empty-desc">Nothing yet. New leads and assignments show up here the moment they arrive.</p>
                </div>
            @else
                <ul class="lq-bell-list flex flex-col gap-0.5 overflow-y-auto p-1.5 lq-live">
                    @foreach ($notifications as $notification)
                        @php
                            $data = $notification->data;
                            $status = $data['status'] ?? 'info';
                        @endphp
                        <li wire:key="notification-{{ $notification->getKey() }}">
                            <a
                                href="{{ $data['url'] ?? '#' }}"
                                class="lq-menu-item items-start"
                                @if ($notification->read_at === null) wire:click="markRead('{{ $notification->getKey() }}')" @endif
                                wire:navigate
                            >
                                <span @class(['lq-logo-tile lq-logo-sm', 'lq-badge-'.$status]) style="box-shadow: none">
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

            {{-- Choices of this computer, kept by the browser (app.js: the alerts and install stores). --}}
            <div class="lq-menu-divider" style="margin: 0"></div>
            <div class="lq-prefs" wire:ignore>
                <div class="lq-prefs-title">On this computer</div>

                <div class="lq-pref" x-show="$store.alerts.permission !== 'unsupported'">
                    <span class="lq-pref-icon"><x-heroicon-o-computer-desktop /></span>
                    <span class="lq-pref-text">
                        <span class="lq-pref-label">Desktop alerts</span>
                        <span class="lq-pref-hint" x-show="$store.alerts.permission !== 'denied'">New leads pop up on your screen while you work in other windows</span>
                        <span class="lq-pref-hint" x-show="$store.alerts.permission === 'denied'" x-cloak>Blocked by the browser. Click the icon left of the address and allow notifications.</span>
                    </span>
                    <button
                        type="button"
                        class="lq-switch"
                        role="switch"
                        aria-label="Desktop alerts"
                        x-bind:aria-checked="$store.alerts.desktop ? 'true' : 'false'"
                        x-bind:disabled="$store.alerts.permission === 'denied'"
                        x-on:click="$store.alerts.toggleDesktop()"
                    ></button>
                </div>

                <div class="lq-pref">
                    <span class="lq-pref-icon">
                        <x-heroicon-o-speaker-wave x-show="$store.alerts.sound" />
                        <x-heroicon-o-speaker-x-mark x-show="! $store.alerts.sound" x-cloak />
                    </span>
                    <span class="lq-pref-text">
                        <span class="lq-pref-label">Sound</span>
                        <span class="lq-pref-hint">A short chime for each new notification</span>
                    </span>
                    <button
                        type="button"
                        class="lq-switch"
                        role="switch"
                        aria-label="Sound"
                        x-bind:aria-checked="$store.alerts.sound ? 'true' : 'false'"
                        x-on:click="$store.alerts.toggleSound()"
                    ></button>
                </div>

                <button type="button" class="lq-pref lq-pref-action" x-show="$store.install.available" x-cloak x-on:click="$store.install.run()">
                    <span class="lq-pref-icon"><x-heroicon-o-arrow-down-tray /></span>
                    <span class="lq-pref-text">
                        <span class="lq-pref-label">Install the app</span>
                        <span class="lq-pref-hint">LiquifyCRM in its own window, on the desktop and taskbar</span>
                    </span>
                </button>
            </div>
        </div>
    @endif
</div>
