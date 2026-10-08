<div class="flex flex-col gap-6">
    <x-ui.page-header title="Email settings" subtitle="The email account {{ $brand->name }} sends from. Used for the automatic reply to customers.">
        <x-slot:actions>
            @if ($saved)
                <x-ui.button variant="ghost" icon="heroicon-o-paper-airplane" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">Send me a test email</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-ui.card title="Email account" description="The outgoing mail (SMTP) details from your email provider. The password is stored encrypted.">
            <x-slot:actions>
                @if ($provider)
                    <x-ui.badge color="info" icon="heroicon-m-envelope">{{ $provider }}</x-ui.badge>
                @endif
            </x-slot:actions>

            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Server" name="host" help="For example smtp.gmail.com, smtp.office365.com or smtp.hostinger.com.">
                    <x-ui.input wire:model.blur="host" name="host" placeholder="smtp.hostinger.com" />
                </x-ui.field>
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Port" name="port">
                        <x-ui.input wire:model="port" name="port" inputmode="numeric" />
                    </x-ui.field>
                    <x-ui.field label="Security" name="encryption" tip="Use TLS with port 587 unless your email provider says otherwise. SSL goes with port 465.">
                        <x-ui.select wire:model="encryption" name="encryption">
                            <option value="tls">TLS (port 587)</option>
                            <option value="ssl">SSL (port 465)</option>
                            <option value="none">None</option>
                        </x-ui.select>
                    </x-ui.field>
                </div>
                <x-ui.field label="Username" name="username" help="Usually the full email address.">
                    <x-ui.input wire:model="username" name="username" autocomplete="off" />
                </x-ui.field>
                <x-ui.field label="Password" name="password" :help="$hasPassword ? 'Saved. Leave empty to keep it.' : ($provider === 'Gmail' ? 'Gmail needs a 16-character app password, not your normal password.' : null)">
                    <x-ui.input type="password" wire:model="password" name="password" autocomplete="new-password" />
                </x-ui.field>
                <x-ui.field label="From address" name="fromAddress">
                    <x-ui.input type="email" wire:model="fromAddress" name="fromAddress" placeholder="hello@ukpublishinghouse.co.uk" />
                </x-ui.field>
                <x-ui.field label="From name" name="fromName">
                    <x-ui.input wire:model="fromName" name="fromName" />
                </x-ui.field>
            </div>
        </x-ui.card>

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

        <div class="flex items-center justify-end gap-2">
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Save email settings</x-ui.button>
        </div>
    </form>
</div>
