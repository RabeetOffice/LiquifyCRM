@php
    use App\Modules\Brands\Support\MailProviders;
    $isOther = $preset === MailProviders::OTHER;
    $isWebmail = $preset === MailProviders::WEBMAIL;
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header title="Email settings" subtitle="The email account {{ $brand->name }} sends from. Used for the automatic reply to customers.">
        <x-slot:actions>
            @if ($saved)
                <x-ui.button variant="ghost" icon="heroicon-o-paper-airplane" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">Send me a test email</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-ui.card title="Who provides your email?" description="Choose the service this mailbox is with. Its server details are filled in for you; you only add the address and password.">
            <div class="flex flex-col gap-5">
                @foreach ($groups as $group => $providers)
                    <div>
                        <div class="lq-side-group-label" style="padding: 0">{{ $group }}</div>
                        <div class="lq-providers" role="radiogroup" aria-label="{{ $group }}">
                            @foreach ($providers as $key => $item)
                                @php $selected = $preset === $key; @endphp
                                <button type="button" role="radio" aria-checked="{{ $selected ? 'true' : 'false' }}" wire:key="provider-{{ $key }}" wire:click="usePreset('{{ $key }}')" @class(['lq-provider', 'lq-provider-active' => $selected])>
                                    @php $icon = MailProviders::iconUrl($key); @endphp
                                    <span @class(['lq-provider-mark', 'lq-provider-mark-icon' => $icon !== null]) style="--provider-color: {{ $item['color'] }}" aria-hidden="true">
                                        <span>{{ $item['mark'] }}</span>
                                        @if ($icon)
                                            <img src="{{ $icon }}" alt="" width="32" height="32" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">
                                        @endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="lq-provider-name">{{ $item['label'] }}</span>
                                        <span class="lq-provider-host">
                                            @if ($key === MailProviders::WEBMAIL)
                                                {{ $webmailHost !== '' ? $webmailHost : 'mail.yourdomain.com' }}
                                            @elseif ($key === MailProviders::OTHER)
                                                Enter the details yourself
                                            @else
                                                {{ $item['host'] }}
                                            @endif
                                        </span>
                                    </span>
                                    @if ($selected)
                                        <x-heroicon-m-check-circle class="lq-provider-check" />
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @if ($preset === '')
                    @error('host') <p class="lq-error">Choose your email provider first, then fill in the details.</p> @enderror
                @endif
            </div>
        </x-ui.card>

        @if ($provider)
            <x-ui.card :title="$isOther ? 'Email account' : $provider['label'].' account'" description="The password is stored encrypted and is never shown again.">
                <div class="lq-form">
                    <div class="lq-alert">
                        <x-heroicon-o-information-circle />
                        <span>{{ $provider['note'] }}</span>
                    </div>

                    <div class="lq-form-grid lq-form-grid-2">
                        <x-ui.field label="Email address (username)" name="username" help="Usually the full email address of the mailbox.">
                            <x-ui.input wire:model="username" name="username" autocomplete="off" placeholder="hello@yourdomain.com" />
                        </x-ui.field>
                        <x-ui.field label="Password" name="password" :help="$hasPassword ? 'Saved. Leave empty to keep it.' : null">
                            <x-ui.input type="password" wire:model="password" name="password" autocomplete="new-password" />
                        </x-ui.field>
                        <x-ui.field label="From address" name="fromAddress" help="What the customer sees as the sender. Usually the same mailbox.">
                            <x-ui.input type="email" wire:model="fromAddress" name="fromAddress" placeholder="hello@yourdomain.com" />
                        </x-ui.field>
                        <x-ui.field label="From name" name="fromName">
                            <x-ui.input wire:model="fromName" name="fromName" />
                        </x-ui.field>
                    </div>

                    <div class="lq-divider"></div>

                    <div>
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <span class="lq-label">Server details</span>
                            <span class="lq-help">{{ $isOther ? 'From your email provider.' : ($isWebmail ? 'Check the server name with your hosting panel.' : 'Filled in for '.$provider['label'].'. Change them only if your provider says so.') }}</span>
                        </div>
                        <div class="lq-form-grid lq-form-grid-3">
                            <x-ui.field label="Server" name="host">
                                <x-ui.input wire:model.blur="host" name="host" :placeholder="$isWebmail ? 'mail.yourdomain.com' : 'smtp.example.com'" autocomplete="off" />
                            </x-ui.field>
                            <x-ui.field label="Port" name="port">
                                <x-ui.input wire:model="port" name="port" inputmode="numeric" />
                            </x-ui.field>
                            <x-ui.field label="Security" name="encryption" tip="TLS goes with port 587 and SSL with port 465. Use what your email provider gives you.">
                                <x-ui.select wire:model.live="encryption" name="encryption">
                                    <option value="tls">TLS (port 587)</option>
                                    <option value="ssl">SSL (port 465)</option>
                                    <option value="none">None</option>
                                </x-ui.select>
                            </x-ui.field>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        @endif

        <x-ui.card title="Automatic reply" description="Sent to the customer once, when a new lead arrives from a website with an email address.">
            <div class="lq-form-grid">
                <x-ui.field name="autoReply">
                    <x-ui.checkbox wire:model.live="autoReply" name="autoReply" label="Send an automatic reply to new website leads" />
                </x-ui.field>
                @if ($autoReply)
                    <x-ui.field label="Subject" name="autoReplySubject" required>
                        <x-ui.input wire:model="autoReplySubject" name="autoReplySubject" />
                    </x-ui.field>
                    <x-ui.field label="Message" name="autoReplyBody" required help="{name} becomes the customer's name and {brand} becomes {{ $brand->name }}.">
                        <x-ui.textarea wire:model="autoReplyBody" name="autoReplyBody" rows="7" />
                    </x-ui.field>
                @endif
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-3">
            @if ($saved)
                <span class="lq-help mr-auto">After saving, use “Send me a test email” above to check that it works.</span>
            @endif
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save email settings</x-ui.button>
        </div>
    </form>
</div>
