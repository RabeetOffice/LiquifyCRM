<div class="flex flex-col gap-6">
    <x-ui.page-header title="Two-step codes" :subtitle="$forIt ? 'Turn two-step on for Managers, Agents and Website leads people from here, and read the code each one\'s app shows right now.' : 'Turn two-step on for people from here, and read the code each one\'s app shows right now. Super Admins look after their own.'">
        @if ($unlocked)
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="heroicon-o-lock-closed" wire:click="lock">Close the codes</x-ui.button>
                @if ($counts['off'] > 0)
                    <x-ui.button variant="primary" icon="heroicon-o-key" wire:click="confirmTurnOnForEveryone">Turn on for everyone ({{ $counts['off'] }})</x-ui.button>
                @endif
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="lq-minis">
        <x-ui.mini label="People" :value="$counts['all']" icon="heroicon-o-users" />
        <x-ui.mini label="Two-step on" :value="$counts['on']" icon="heroicon-o-shield-check" :tone="$counts['on'] > 0 ? 'ok' : null" />
        <x-ui.mini label="Without two-step" :value="$counts['off']" icon="heroicon-o-shield-exclamation" :tone="$counts['off'] > 0 ? 'warn' : null" />
        <x-ui.mini label="Codes change in" icon="heroicon-o-clock">
            <span x-data="{ left: {{ $secondsLeft }} }" x-init="setInterval(() => { left = 30 - (Math.floor(Date.now() / 1000) % 30) }, 1000)" class="lq-tabular"><span x-text="left">{{ $secondsLeft }}</span> s</span>
        </x-ui.mini>
    </div>

    @unless ($unlocked)
        <x-ui.card>
            <div class="lq-twostep-locked">
                <span class="lq-twostep-locked-icon"><x-heroicon-o-lock-closed /></span>
                <div class="min-w-0">
                    <h2 class="lq-twostep-locked-title">The codes are closed</h2>
                    <p class="lq-help">Anyone who sees a person's code or QR code can pass their two-step, so the codes open only after your password, for 10 minutes on this browser. Opening them is written in the logs.</p>
                </div>
                <x-ui.button variant="primary" icon="heroicon-o-lock-open" wire:click="unlock">Open the codes</x-ui.button>
            </div>
        </x-ui.card>
    @endunless

    <x-ui.card flush class="lq-list">
        <div class="lq-viewbar">
            <div class="lq-views" role="tablist">
                @foreach (['all' => 'Everyone', 'on' => 'Two-step on', 'off' => 'Without two-step'] as $key => $label)
                    <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" @class(['lq-view', 'lq-view-active' => $tab === $key]) aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                        {{ $label }} <span class="lq-view-count">{{ $counts[$key] }}</span>
                    </button>
                @endforeach
            </div>
            <div class="lq-viewbar-end">
                <label class="lq-search">
                    <x-heroicon-o-magnifying-glass />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or email…" aria-label="Search people">
                </label>
            </div>
        </div>

        @if ($people->isEmpty())
            <x-ui.empty title="Nobody here" description="Nobody matches this view." icon="heroicon-o-key" />
        @else
            {{-- Every code changes on the same 30-second step: the list asks the server again when it turns. --}}
            <div class="lq-table-wrap" @if ($unlocked) x-data="{ step: Math.floor(Date.now() / 30000) }" x-init="setInterval(() => { const now = Math.floor(Date.now() / 30000); if (now !== step) { step = now; $wire.$refresh() } }, 1000)" @endif>
                <table class="lq-table lq-table-rows">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th>Role</th>
                            <th>Two-step</th>
                            <th>Code now</th>
                            <th class="lq-table-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $person)
                            @php
                                $on = $person->hasTwoFactorEnabled();
                                $code = $codes[$person->getKey()] ?? null;
                                $roles = $person->role ? collect([$person->role->getLabel()]) : $person->memberships()->acrossBrands()->pluck('role')->map(fn ($role) => $role->getLabel())->unique();
                            @endphp
                            <tr wire:key="two-step-{{ $person->getKey() }}-{{ $on ? 'on' : 'off' }}">
                                <td class="lq-td-person">
                                    <span class="lq-person">
                                        <x-ui.avatar :name="$person->name" :src="$person->avatarUrl()" />
                                        <span class="min-w-0">
                                            <span class="lq-cell-title">{{ $person->name }}</span>
                                            <span class="lq-cell-sub" title="{{ $person->email }}">{{ $person->email }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td class="lq-muted">{{ $roles->isEmpty() ? 'No role yet' : $roles->join(', ', ' and ') }}</td>
                                <td>
                                    @if ($on)
                                        <x-ui.badge color="success" icon="heroicon-m-shield-check">On</x-ui.badge>
                                    @else
                                        <x-ui.badge color="gray">Off</x-ui.badge>
                                    @endif
                                </td>
                                <td>
                                    @if (! $on)
                                        <span class="lq-muted">—</span>
                                    @elseif ($code === null)
                                        <span class="lq-twostep-code lq-twostep-code-hidden" title="Open the codes to see it">••• •••</span>
                                    @else
                                        <button type="button" class="lq-twostep-code" title="Copy the code" x-data x-on:click="navigator.clipboard?.writeText('{{ $code }}'); $store.toasts.push({ title: 'Code copied', duration: 2000 })">{{ substr($code, 0, 3) }} {{ substr($code, 3) }}</button>
                                    @endif
                                </td>
                                <td class="lq-table-end">
                                    @if ($unlocked)
                                        <div class="lq-row-tools">
                                            @if ($on)
                                                <x-ui.button size="sm" variant="ghost" icon="heroicon-o-qr-code" wire:click="showQrFor({{ $person->getKey() }})">QR code</x-ui.button>
                                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon" wire:click="confirmRenew({{ $person->getKey() }})" title="New key" aria-label="Give {{ $person->name }} a new key"><x-heroicon-o-arrow-path /></button>
                                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="confirmTurnOff({{ $person->getKey() }})" title="Turn off" aria-label="Turn off two-step for {{ $person->name }}"><x-heroicon-o-no-symbol /></button>
                                            @else
                                                <x-ui.button size="sm" variant="secondary" icon="heroicon-o-key" wire:click="turnOn({{ $person->getKey() }})" wire:loading.attr="disabled">Turn on</x-ui.button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $people->links('pagination.lq') }}
        @endif
    </x-ui.card>

    <x-ui.modal model="showQr" :title="$qrPerson ? 'QR code of '.$qrPerson->name : 'QR code'" description="Scan it with Google Authenticator or any authenticator app: on their phone, or on yours to read their codes there too." size="sm">
        @if ($qrPerson !== null && $qrSvg !== null)
            <div class="lq-twostep-qr">
                <div class="lq-twostep-qr-image">{!! $qrSvg !!}</div>
                <p class="lq-help">Or type this key into the app by hand:</p>
                <code class="lq-code lq-twostep-key">{{ trim(chunk_split((string) $qrSecret, 4, ' ')) }}</code>
                <p class="lq-help">The app shows it as {{ config('app.name') }} ({{ $qrPerson->email }}).</p>
            </div>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" wire:click="closeQr">Done</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.confirm :confirm="$confirm" />
</div>
