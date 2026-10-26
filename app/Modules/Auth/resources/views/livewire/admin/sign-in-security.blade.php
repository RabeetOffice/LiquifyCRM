<div class="flex flex-col gap-6" wire:poll.60s.visible>
    <x-ui.page-header title="Sign-in security" subtitle="Where people may sign in from: the office network, phones and tablets, and internet addresses you block." />

    @if (! $ready)
        <x-ui.card>
            <div class="lq-alert lq-alert-warning">
                <x-heroicon-o-arrow-up-circle />
                <span>Sign-in security comes with this version's database update. Open <a class="lq-link" href="{{ route('admin.updates') }}" wire:navigate>Updates</a> and click <strong>Update system</strong>, then come back here.</span>
            </div>
        </x-ui.card>
    @else
        @unless ($enforced)
            <div class="lq-alert lq-alert-warning">
                <x-heroicon-o-exclamation-triangle />
                <span><strong>Switched off on the server.</strong> The .env file says <code class="lq-code">LIQUIFY_SIGN_IN_RULES=false</code>, so nothing here is checked. Remove that line once the rules are right.</span>
            </div>
        @endunless

        <div class="lq-minis">
            <x-ui.mini label="Your internet address" :value="$ip" icon="heroicon-o-globe-alt" :tone="$atOffice ? 'ok' : null" :hint="$atOffice ? 'One of the office addresses' : 'Not an office address'" />
            <x-ui.mini label="You are on" :value="$here->name" :icon="$here->kind->icon()" />
            <x-ui.mini label="Everybody signs in" :value="$rules->network->getLabel()" icon="heroicon-o-building-office-2" />
            <x-ui.mini label="Phones" :value="$counts['waiting'] > 0 ? $counts['waiting'].' waiting for you' : $rules->phones->getLabel()" icon="heroicon-o-device-phone-mobile" :tone="$counts['waiting'] > 0 ? 'warn' : null" :hint="$rules->phones->getLabel()" />
        </div>

        {{-- The rule for everybody --}}
        <x-ui.card title="Rules for everybody" description="Anyone without a rule of their own (People › Edit) follows these. A change counts from everybody's next click: whoever it refuses is signed out and told why.">
            <form wire:submit="saveRules" class="flex flex-col gap-5">
                <fieldset class="lq-rulegroup">
                    <legend class="lq-label">Where they can sign in from</legend>
                    <div class="lq-choices">
                        @foreach ($networkRules as $rule)
                            <label @class(['lq-choice', 'lq-choice-active' => $network === $rule->value])>
                                <input type="radio" wire:model.live="network" value="{{ $rule->value }}" name="network">
                                <span class="flex items-center gap-2 lq-choice-title">@svg($rule->value === 'office' ? 'heroicon-o-building-office-2' : 'heroicon-o-globe-alt', 'h-4 w-4') {{ $rule->getLabel() }}</span>
                                <span class="lq-choice-desc">{{ $rule->description() }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('network') <p class="lq-error mt-2">{{ $message }}</p> @enderror
                </fieldset>

                <fieldset class="lq-rulegroup">
                    <legend class="lq-label">Phones and tablets</legend>
                    <div class="lq-choices">
                        @foreach ($phoneRules as $rule)
                            <label @class(['lq-choice', 'lq-choice-active' => $phones === $rule->value])>
                                <input type="radio" wire:model.live="phones" value="{{ $rule->value }}" name="phones">
                                <span class="flex items-center gap-2 lq-choice-title">@svg(match ($rule->value) { 'any' => 'heroicon-o-device-phone-mobile', 'approved' => 'heroicon-o-check-badge', default => 'heroicon-o-no-symbol' }, 'h-4 w-4') {{ $rule->getLabel() }}</span>
                                <span class="lq-choice-desc">{{ $rule->description() }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('phones') <p class="lq-error mt-2">{{ $message }}</p> @enderror
                </fieldset>

                <div class="lq-rulefoot">
                    <p class="lq-help">
                        @if ($myNetwork || $myPhones)
                            Your own rule: {{ $myNetwork?->getLabel() ?? 'network as for everybody' }}, {{ mb_strtolower($myPhones?->getLabel() ?? 'phones as for everybody') }}.
                        @else
                            You follow these rules too. Nothing that would sign you out can be saved.
                        @endif
                    </p>
                    <x-ui.button type="submit" variant="primary" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="saveRules">Save rules</x-ui.button>
                </div>
            </form>

            @if ($ownRules->isNotEmpty())
                <div class="lq-ownrules">
                    <p class="lq-access-add-title"><x-heroicon-o-user-circle /> People with a rule of their own</p>
                    <ul class="lq-ownrules-list">
                        @foreach ($ownRules as $person)
                            @php
                                $ownNetwork = \App\Modules\Auth\Enums\NetworkRule::tryFrom((string) $person->sign_in_network);
                                $ownPhones = \App\Modules\Auth\Enums\PhoneRule::tryFrom((string) $person->sign_in_phones);
                            @endphp
                            <li wire:key="own-{{ $person->getKey() }}">
                                <a href="{{ route('admin.people.edit', ['userId' => $person->getKey()]) }}" class="lq-ownrule" wire:navigate title="Change on People › Edit">
                                    <x-ui.avatar :name="$person->name" :src="$person->avatarUrl()" size="sm" />
                                    <span class="min-w-0">
                                        <span class="lq-cell-title truncate">{{ $person->name }}</span>
                                        <span class="lq-cell-sub">{{ $ownNetwork?->getLabel() ?? 'Network as for everybody' }} · {{ $ownPhones?->getLabel() ?? 'phones as for everybody' }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-ui.card>

        <div class="grid gap-6 xl:grid-cols-2">
            {{-- Office network --}}
            <x-ui.card title="Office network" description="The office's internet addresses. People with the office rule can sign in only from these. A range such as 203.0.113.0/24 covers a block.">
                @if ($office->isEmpty())
                    <p class="lq-help">No office address yet. In the office, click <strong>Add my address</strong>: it adds the address this computer is on.</p>
                @else
                    <ul class="lq-netlist">
                        @foreach ($office as $row)
                            <li class="lq-netrow" wire:key="office-{{ $row->getKey() }}">
                                <span @class(['lq-netrow-icon', 'lq-netrow-icon-here' => \App\Modules\Auth\Support\SignInRules::matches($ip, [$row->address])])><x-heroicon-o-building-office-2 /></span>
                                <span class="min-w-0">
                                    <span class="lq-netrow-address">{{ $row->address }}</span>
                                    <span class="lq-cell-sub truncate">{{ collect([$row->label, \App\Modules\Auth\Support\SignInRules::matches($ip, [$row->address]) ? 'you are on it now' : null, $row->creator ? 'added by '.$row->creator->name : null])->filter()->implode(' · ') }}</span>
                                </span>
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-sm lq-btn-icon-danger" wire:click="removeOffice({{ $row->getKey() }})" title="Remove {{ $row->address }}" aria-label="Remove {{ $row->address }}"><x-heroicon-o-x-mark /></button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form wire:submit="addOffice" class="lq-netadd">
                    <x-ui.input wire:model="officeAddress" name="officeAddress" class="lq-input-sm" placeholder="203.0.113.7" aria-label="Office address" autocomplete="off" />
                    <x-ui.input wire:model="officeLabel" name="officeLabel" class="lq-input-sm" placeholder="Name, e.g. Lahore office" aria-label="Name of this address" autocomplete="off" />
                    <x-ui.button type="submit" size="sm" icon="heroicon-o-plus" wire:loading.attr="disabled" wire:target="addOffice">Add</x-ui.button>
                </form>
                @error('officeAddress') <p class="lq-error mt-2">{{ $message }}</p> @enderror

                @unless ($atOffice)
                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-sm mt-3" wire:click="addMyAddress" wire:loading.attr="disabled" wire:target="addMyAddress">
                        <x-heroicon-o-map-pin /> Add my address ({{ $ip }})
                    </button>
                @endunless
            </x-ui.card>

            {{-- Blocked addresses --}}
            <x-ui.card title="Blocked addresses" description="Nobody can open LiquifyCRM from these, signed in or not. Whoever is signed in there is signed out at once.">
                @if ($blocked->isEmpty())
                    <p class="lq-help">No address blocked. You can also block one straight from the sign-in list below.</p>
                @else
                    <ul class="lq-netlist">
                        @foreach ($blocked as $row)
                            <li class="lq-netrow" wire:key="blocked-{{ $row->getKey() }}">
                                <span class="lq-netrow-icon lq-netrow-icon-blocked"><x-heroicon-o-no-symbol /></span>
                                <span class="min-w-0">
                                    <span class="lq-netrow-address">{{ $row->address }}</span>
                                    <span class="lq-cell-sub truncate">{{ collect([$row->label, $row->creator ? 'blocked by '.$row->creator->name : null, $row->created_at?->diffForHumans()])->filter()->implode(' · ') }}</span>
                                </span>
                                <button type="button" class="lq-btn lq-btn-ghost lq-btn-sm" wire:click="removeBlocked({{ $row->getKey() }})" title="Unblock {{ $row->address }}">Unblock</button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form wire:submit="addBlocked" class="lq-netadd">
                    <x-ui.input wire:model="blockedAddress" name="blockedAddress" class="lq-input-sm" placeholder="198.51.100.23" aria-label="Address to block" autocomplete="off" />
                    <x-ui.input wire:model="blockedLabel" name="blockedLabel" class="lq-input-sm" placeholder="Why, e.g. former agent" aria-label="Why it is blocked" autocomplete="off" />
                    <x-ui.button type="submit" size="sm" icon="heroicon-o-no-symbol" wire:loading.attr="disabled" wire:target="addBlocked">Block</x-ui.button>
                </form>
                @error('blockedAddress') <p class="lq-error mt-2">{{ $message }}</p> @enderror
            </x-ui.card>
        </div>

        {{-- Phones and tablets --}}
        <x-ui.card flush title="Phones and tablets" description="Every phone or tablet somebody signed in on, or tried to. Approve the ones that may be used; blocking a phone refuses it to everybody who used it.">
            <x-slot:actions>
                <div class="lq-views" role="tablist" aria-label="Which phones to show">
                    @foreach (['waiting' => 'Waiting', 'approved' => 'Approved', 'blocked' => 'Blocked', 'all' => 'All'] as $key => $label)
                        <button type="button" role="tab" @class(['lq-view', 'lq-view-active' => $devices === $key]) wire:click="$set('devices', '{{ $key }}')" aria-selected="{{ $devices === $key ? 'true' : 'false' }}">
                            {{ $label }} <span class="lq-muted lq-tabular">{{ $counts[$key] }}</span>
                        </button>
                    @endforeach
                </div>
            </x-slot:actions>

            @if ($deviceList->isEmpty())
                <x-ui.empty :title="$devices === 'waiting' ? 'No phone is waiting' : 'No phone here yet'" description="When somebody signs in on a phone or tablet, or tries to, it shows here." icon="heroicon-o-device-phone-mobile" />
            @else
                <div class="lq-table-wrap">
                    <table class="lq-table lq-table-rows">
                        <thead>
                            <tr>
                                <th>Person</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th class="lq-col-wide">Last used</th>
                                <th class="lq-table-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($deviceList as $device)
                                <tr wire:key="device-{{ $device->getKey() }}-{{ $device->status->value }}">
                                    <td class="lq-td-person">
                                        <span class="lq-person">
                                            <x-ui.avatar :name="$device->user->name ?? '?'" :src="$device->user?->avatarUrl()" />
                                            <span class="min-w-0">
                                                <span class="lq-cell-title">{{ $device->user->name ?? 'Former member' }}</span>
                                                <span class="lq-cell-sub">{{ $device->user->email ?? '' }}</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="lq-device">
                                            @svg($device->kind->icon())
                                            <span class="min-w-0">
                                                <span class="lq-cell-title truncate" title="{{ $device->user_agent }}">{{ $device->name }}</span>
                                                <span class="lq-cell-sub">First seen {{ $device->created_at?->diffForHumans() }}{{ $device->first_ip ? ' from '.$device->first_ip : '' }}</span>
                                            </span>
                                        </span>
                                    </td>
                                    <td>
                                        <x-ui.badge :color="$device->status->getColor()">{{ $device->status->getLabel() }}</x-ui.badge>
                                        @if ($device->decider && in_array($device->status->value, ['approved', 'blocked'], true))
                                            <span class="lq-cell-sub">by {{ $device->decider->name }}</span>
                                        @endif
                                    </td>
                                    <td class="lq-col-wide lq-muted whitespace-nowrap">
                                        <span title="{{ $device->last_seen_at?->format('j M Y, H:i') }} UTC">{{ $device->last_seen_at?->diffForHumans() ?? '—' }}</span>
                                        @if ($device->last_ip)
                                            <span class="lq-cell-sub lq-tabular">{{ $device->last_ip }}</span>
                                        @endif
                                    </td>
                                    <td class="lq-table-end">
                                        <div class="lq-row-tools">
                                            @if ($device->status->value !== 'approved')
                                                <x-ui.button size="sm" variant="secondary" icon="heroicon-o-check" wire:click="approveDevice({{ $device->getKey() }})" wire:loading.attr="disabled">Approve</x-ui.button>
                                            @endif
                                            @if ($device->status->value !== 'blocked')
                                                <x-ui.button size="sm" variant="ghost" icon="heroicon-o-no-symbol" wire:click="blockDevice({{ $device->getKey() }})" wire:loading.attr="disabled">Block</x-ui.button>
                                            @endif
                                            <button type="button" class="lq-btn lq-btn-ghost lq-btn-icon lq-btn-icon-danger" wire:click="forgetDevice({{ $device->getKey() }})" title="Remove from the list" aria-label="Remove {{ $device->name }} of {{ $device->user->name ?? 'a former member' }} from the list"><x-heroicon-o-trash /></button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        {{-- Who tried --}}
        <x-ui.card flush title="Who tried to sign in" description="Refused sign-ins and sign-ins on phones, newest first. Kept as long as the logs are (Logs screen).">
            <x-slot:actions>
                <div class="lq-views" role="tablist" aria-label="Which sign-ins to show">
                    @foreach (['all' => 'All', 'refused' => 'Refused', 'phones' => 'On phones'] as $key => $label)
                        <button type="button" role="tab" @class(['lq-view', 'lq-view-active' => $feed === $key]) wire:click="$set('feed', '{{ $key }}')" aria-selected="{{ $feed === $key ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </x-slot:actions>

            @if ($entries->isEmpty())
                <x-ui.empty title="Nothing yet" description="Refused sign-ins and sign-ins on phones show here as they happen." icon="heroicon-o-finger-print" />
            @else
                <ol class="lq-signins">
                    @foreach ($entries as $entry)
                        @php
                            $kind = \App\Modules\Auth\Enums\DeviceKind::tryFrom((string) ($entry->properties['device'] ?? ''));
                            $reason = $refusals[$entry->properties['reason'] ?? ''] ?? ($entry->event === 'auth.address_blocked' ? 'Blocked address' : null);
                            $refused = $entry->event !== 'auth.login';
                            $canBlock = $entry->ip && ! $rules->isBlocked($entry->ip) && ! $rules->isOffice($entry->ip) && $entry->ip !== $ip;
                        @endphp
                        <li @class(['lq-signin', 'lq-signin-refused' => $refused]) wire:key="signin-{{ $entry->getKey() }}">
                            <span class="lq-signin-icon">@svg($kind?->icon() ?? ($entry->event === 'auth.address_blocked' ? 'heroicon-o-no-symbol' : 'heroicon-o-computer-desktop'))</span>
                            <span class="lq-signin-body">
                                <span class="lq-signin-title">
                                    {{ $entry->causer?->name ?? 'Somebody not signed in' }}
                                    @if ($refused)
                                        <x-ui.badge color="danger">{{ $reason ?? 'Refused' }}</x-ui.badge>
                                    @else
                                        <x-ui.badge color="success">Signed in</x-ui.badge>
                                    @endif
                                </span>
                                <span class="lq-signin-meta">
                                    {{ collect([$entry->properties['device_name'] ?? null, $entry->ip, ($entry->properties['during'] ?? null) === 'session' ? 'signed out while working' : null])->filter()->implode(' · ') }}
                                </span>
                            </span>
                            <span class="lq-signin-side">
                                <span class="lq-feed-time lq-tabular" title="{{ $entry->created_at?->format('j M Y, H:i:s') }} UTC">{{ $entry->created_at?->diffForHumans() }}</span>
                                @if ($canBlock)
                                    <button type="button" class="lq-btn lq-btn-ghost lq-btn-sm" wire:click="blockAddress('{{ $entry->ip }}')" wire:loading.attr="disabled" title="Nobody could open LiquifyCRM from {{ $entry->ip }} any more">Block address</button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.card>
    @endif
</div>
