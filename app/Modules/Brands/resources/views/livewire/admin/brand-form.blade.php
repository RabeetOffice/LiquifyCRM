<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$title" :breadcrumbs="['Brands' => route('admin.brands.index'), $title => null]" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-ui.card title="Brand" description="The workspace that owns websites, Facebook Pages, leads and people.">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Brand name" name="name" required>
                    <x-ui.input wire:model.blur="name" name="name" placeholder="UK Publishing House" required autofocus />
                </x-ui.field>
                <x-ui.field label="Address" name="slug" required help="Used in the address bar. Lowercase letters, numbers and dashes.">
                    <div class="lq-input-group">
                        <span class="lq-input-prefix">{{ url('/b') }}/</span>
                        <x-ui.input wire:model="slug" name="slug" required />
                    </div>
                </x-ui.field>
                <x-ui.field label="Website" name="website" help="The favicon and colours are fetched from here when the brand is added.">
                    <x-ui.input type="url" wire:model="website" name="website" placeholder="https://ukpublishinghouse.co.uk" />
                </x-ui.field>
                <x-ui.field label="Currency" name="currency" required help="Every sale in this brand is recorded in this currency.">
                    <x-ui.select wire:model="currency" name="currency">
                        @foreach ($currencies as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Timezone" name="timezone" required>
                    <x-ui.select wire:model="timezone" name="timezone">
                        @foreach ($timezones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field name="is_active" help="Inactive brands are hidden from everyone and stop receiving leads.">
                    <span class="lq-label">Status</span>
                    <x-ui.checkbox wire:model="is_active" name="is_active" label="Active" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Look" description="Logo and colours used for the brand chip, chart line and header strip. The app itself stays Liquify Orange.">
            @if ($brand)
                <x-slot:actions>
                    <x-ui.button variant="secondary" icon="heroicon-o-arrow-down-tray" wire:click="fetchIdentity" wire:loading.attr="disabled" wire:target="fetchIdentity">Fetch from website</x-ui.button>
                </x-slot:actions>
            @endif
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Logo" name="logo" help="SVG or PNG on a transparent background works best.">
                    <div class="flex items-center gap-3">
                        @if ($logo?->temporaryUrl() ?? $brand?->logoUrl())
                            <img src="{{ $logo?->temporaryUrl() ?? $brand?->logoUrl() }}" alt="" class="h-10 w-auto max-w-32 object-contain">
                        @endif
                        <input type="file" wire:model="logo" accept="image/*" class="lq-input" style="padding: 0.375rem 0.625rem">
                    </div>
                </x-ui.field>
                <x-ui.field label="Favicon" name="favicon">
                    <div class="flex items-center gap-3">
                        @if ($favicon?->temporaryUrl() ?? $brand?->faviconUrl())
                            <img src="{{ $favicon?->temporaryUrl() ?? $brand?->faviconUrl() }}" alt="" class="h-8 w-8 rounded-md object-contain">
                        @endif
                        <input type="file" wire:model="favicon" accept="image/*" class="lq-input" style="padding: 0.375rem 0.625rem">
                    </div>
                </x-ui.field>
                <x-ui.field label="Primary colour" name="primaryColor">
                    <div class="flex items-center gap-2">
                        <input type="color" wire:model.live="primaryColor" class="h-10 w-12 cursor-pointer rounded-lg border-0 bg-transparent p-0">
                        <x-ui.input wire:model="primaryColor" name="primaryColor" placeholder="#FF6B2C" class="lq-tabular" />
                    </div>
                </x-ui.field>
                <x-ui.field label="Secondary colour" name="secondaryColor">
                    <div class="flex items-center gap-2">
                        <input type="color" wire:model.live="secondaryColor" class="h-10 w-12 cursor-pointer rounded-lg border-0 bg-transparent p-0">
                        <x-ui.input wire:model="secondaryColor" name="secondaryColor" placeholder="#000000" class="lq-tabular" />
                    </div>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Rules" description="Defaults that apply inside this brand.">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Reveals per Agent per hour" name="revealLimit" required help="How many phone numbers or emails one Agent may reveal in an hour.">
                    <x-ui.input type="number" min="1" max="500" wire:model="revealLimit" name="revealLimit" />
                </x-ui.field>
                <x-ui.field label="Notification emails" name="notificationEmails" help="One per line. Who hears about new leads before they are sent to a department.">
                    <x-ui.textarea wire:model="notificationEmails" name="notificationEmails" rows="3" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.brands.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">{{ $brand ? 'Save changes' : 'Create brand' }}</x-ui.button>
        </div>
    </form>
</div>
