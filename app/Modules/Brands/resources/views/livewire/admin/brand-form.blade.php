@php
    $shownLogo = $logo?->isPreviewable() ? $logo->temporaryUrl() : $brand?->logoUrl();
    $shownFavicon = $favicon?->isPreviewable() ? $favicon->temporaryUrl() : $brand?->faviconUrl();
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$title" :breadcrumbs="['Brands' => route('admin.brands.index'), $title => null]" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-ui.card title="Brand" description="The workspace that owns websites, Facebook Pages, leads and people.">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Brand name" name="name" required>
                    <x-ui.input wire:model.blur="name" name="name" placeholder="UK Publishing House" required autofocus />
                </x-ui.field>
                <x-ui.field label="Link" name="slug" required help="Where the brand lives in LiquifyCRM: {{ url('/b') }}/… Lowercase letters, numbers and dashes.">
                    <div class="lq-input-group">
                        <span class="lq-input-prefix">/b/</span>
                        <x-ui.input wire:model="slug" name="slug" required />
                    </div>
                </x-ui.field>
                <x-ui.field label="Website" name="website" help="The company details, logo, favicon and colour can be read from here.">
                    <x-ui.input type="url" wire:model="website" name="website" placeholder="https://ukpublishinghouse.co.uk" />
                </x-ui.field>
                <x-ui.field label="Currency" name="currency" required help="Every sale in this brand is recorded in this currency.">
                    <x-ui.select wire:model="currency" name="currency">
                        @foreach ($currencies as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Time zone" name="timezone" required for="timezone" help="Dates, due times and daily numbers of this brand follow it.">
                    <x-ui.picker model="timezone" name="timezone" :options="$timezones" :pinned="\App\Modules\Core\Support\Timezones::COMMON" placeholder="Search a city or a country…" more="Type a city or a country to see every time zone." />
                </x-ui.field>
                <x-ui.field name="is_active" help="Inactive brands are hidden from everyone and stop receiving leads.">
                    <span class="lq-label">Status</span>
                    <x-ui.checkbox wire:model="is_active" name="is_active" label="Active" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Company details" description="Who the brand is on paper. Its invoices and contracts will carry these, so keep them as the company writes them.">
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" icon="heroicon-m-sparkles" wire:click="fillCompany" wire:loading.attr="disabled" wire:target="fillCompany">Fill from website</x-ui.button>
            </x-slot:actions>
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Phone" name="phone">
                    <x-ui.input wire:model="phone" name="phone" placeholder="+44 20 8135 2515" inputmode="tel" />
                </x-ui.field>
                <x-ui.field label="Email" name="email">
                    <x-ui.input type="email" wire:model="email" name="email" placeholder="info@ukpublishinghouse.co.uk" />
                </x-ui.field>
                <x-ui.field label="Address" name="address">
                    <x-ui.textarea wire:model="address" name="address" rows="4" placeholder="60 Tottenham Court Road&#10;London W1T 2EW&#10;United Kingdom" />
                </x-ui.field>
                <div class="lq-form">
                    <x-ui.field label="Registered company name" name="legalName" tip="The name on paper, when it differs from the brand's name: for example UK Publishing House Ltd.">
                        <x-ui.input wire:model="legalName" name="legalName" placeholder="UK Publishing House Ltd" />
                    </x-ui.field>
                    <div class="lq-form-grid lq-form-grid-2">
                        <x-ui.field label="Company number" name="registrationNumber">
                            <x-ui.input wire:model="registrationNumber" name="registrationNumber" />
                        </x-ui.field>
                        <x-ui.field label="VAT or tax number" name="taxNumber">
                            <x-ui.input wire:model="taxNumber" name="taxNumber" />
                        </x-ui.field>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Invoices" description="What the brand's invoices carry, next to the company details above. An invoice is made from a sale, on the lead or the client.">
            <div class="lq-form">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Invoice number starts with" name="invoicePrefix" help="Letters and numbers, up to 10. The number then reads like UKPH-2026-0001.">
                        <x-ui.input wire:model="invoicePrefix" name="invoicePrefix" placeholder="UKPH" />
                    </x-ui.field>
                    <x-ui.field label="Days to pay" name="invoiceDueDays" help="The due date is this many days after the invoice date. 0 for none.">
                        <x-ui.input type="number" min="0" max="90" wire:model="invoiceDueDays" name="invoiceDueDays" />
                    </x-ui.field>
                </div>
                <x-ui.field label="How to pay" name="invoicePayment" help="Bank details or a payment link, printed on every invoice.">
                    <textarea class="lq-input" rows="4" wire:model="invoicePayment" name="invoicePayment" id="invoicePayment" placeholder="Bank: …&#10;Account name: …&#10;Sort code: …  Account number: …"></textarea>
                </x-ui.field>
                <x-ui.field label="Note at the bottom" name="invoiceNote" help="Optional, such as thanks or your terms.">
                    <textarea class="lq-input" rows="2" wire:model="invoiceNote" name="invoiceNote" id="invoiceNote" placeholder="Thank you for choosing us."></textarea>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Look" description="The brand's logo, mark and colour. The app itself stays Liquify Orange.">
            @if ($brand)
                <x-slot:actions>
                    <x-ui.button variant="secondary" size="sm" icon="heroicon-o-arrow-down-tray" wire:click="fetchIdentity" wire:loading.attr="disabled" wire:target="fetchIdentity">Fetch from website</x-ui.button>
                </x-slot:actions>
            @endif
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Logo" name="logo" help="For the brand's own pages and, later, its invoices and contracts. A dark logo on a see-through background works best. Up to 2 MB.">
                    <div class="lq-logo-row">
                        <div class="lq-logo-strip">
                            @if ($shownLogo)
                                <img src="{{ $shownLogo }}" alt="">
                            @else
                                <span>No logo yet</span>
                            @endif
                        </div>
                        <input type="file" class="lq-input lq-input-file" wire:model="logo" name="logo" id="logo" accept="image/png,image/jpeg,image/webp,image/gif">
                    </div>
                </x-ui.field>
                <x-ui.field label="Favicon" name="favicon" help="The small square mark shown beside the brand's name everywhere. Up to 1 MB.">
                    <div class="lq-logo-row">
                        <x-ui.avatar :name="$name" :src="$shownFavicon" :color="$accent" :tile="$tile" size="lg" brand wire:key="favicon-{{ md5((string) $shownFavicon) }}" />
                        <input type="file" class="lq-input lq-input-file" wire:model="favicon" name="favicon" id="favicon" accept="image/png,image/jpeg,image/webp,image/gif">
                    </div>
                </x-ui.field>
                <x-ui.field label="Main colour" name="primaryColor" help="The tile behind the brand's mark. White suits most logos; choose a dark colour for a white logo.">
                    <div class="lq-swatch">
                        <input type="color" wire:model.live="primaryColor" aria-label="Main colour">
                        <input type="text" class="lq-swatch-text" wire:model.blur="primaryColor" name="primaryColor" maxlength="7" placeholder="#FFFFFF">
                    </div>
                </x-ui.field>
                <x-ui.field label="Second colour" name="secondaryColor" help="The brand's own colour: the ring around its mark, its chips and its line in charts.">
                    <div class="lq-swatch">
                        <input type="color" wire:model.live="secondaryColor" aria-label="Second colour" value="{{ $secondaryColor !== '' ? $secondaryColor : '#ff6b2c' }}">
                        <input type="text" class="lq-swatch-text" wire:model.blur="secondaryColor" name="secondaryColor" maxlength="7" placeholder="None">
                    </div>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Rules" description="Defaults that apply inside this brand.">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Reveals per Agent per hour" name="revealLimit" required help="How many phone numbers or emails one Agent may reveal in an hour." tip="Agents see contact details masked until they click Reveal. Each reveal is logged. This limit stops someone copying the whole list; Managers and Admins are not limited.">
                    <x-ui.input type="number" min="1" max="500" wire:model="revealLimit" name="revealLimit" />
                </x-ui.field>
                <x-ui.field label="Notification emails" name="notificationEmails" help="One per line. Who hears about new leads before they are sent to a department." tip="These addresses get an email for every new lead of this brand, even without a LiquifyCRM login. Department Managers are told separately once a lead is sent to them.">
                    <x-ui.textarea wire:model="notificationEmails" name="notificationEmails" rows="3" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.brands.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save, logo, favicon">{{ $brand ? 'Save changes' : 'Create brand' }}</x-ui.button>
        </div>
    </form>
</div>
