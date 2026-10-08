<div class="flex flex-col gap-6">
    <x-ui.page-header title="Add brand" :breadcrumbs="['Brands' => route('admin.brands.index'), 'Add brand' => null]" />

    <ol class="lq-wizard">
        @foreach ([1 => 'The basics', 2 => 'The look', 3 => 'Check'] as $number => $label)
            <li @class(['lq-wizard-step', 'lq-wizard-active' => $step === $number, 'lq-wizard-done' => $step > $number])>
                <span><span class="lq-wizard-mark">@if ($step > $number)<x-heroicon-m-check />@else{{ $number }}@endif</span>{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    @if ($step === 1)
        <form wire:submit="createBrand">
            <x-ui.card title="The basics" description="A brand is a workspace: it owns its websites, Facebook Pages, leads and people.">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Brand name" name="name" required>
                        <x-ui.input wire:model.blur="name" name="name" placeholder="UK Publishing House" required autofocus />
                    </x-ui.field>
                    <x-ui.field label="Address" name="slug" required tip="The short name in the address bar. Lowercase letters, numbers and dashes. It is hard to change later because people bookmark it.">
                        <div class="lq-input-group">
                            <span class="lq-input-prefix">{{ url('/b') }}/</span>
                            <x-ui.input wire:model="slug" name="slug" required />
                        </div>
                    </x-ui.field>
                    <x-ui.field label="Website" name="website" help="The favicon and colours are read from here in the next step." class="lq-col-full">
                        <x-ui.input wire:model="website" name="website" placeholder="https://ukpublishinghouse.co.uk" />
                    </x-ui.field>
                    <x-ui.field label="Currency" name="currency" required tip="Every sale in this brand is recorded in this currency. Totals across brands are converted with the monthly exchange rates.">
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
                </div>

                <x-slot:footer>
                    <x-ui.button variant="ghost" :href="route('admin.brands.index')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createBrand">Create brand</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>
    @endif

    @if ($step === 2 && $brand)
        <form wire:submit="saveLook">
            <x-ui.card title="The look" description="Used for this brand's chip, chart line and header strip, so it is recognisable at a glance. LiquifyCRM's own buttons stay orange.">
                <x-slot:actions>
                    @if ($brand->website)
                        <x-ui.button variant="secondary" icon="heroicon-o-arrow-path" wire:click="fetchAgain" wire:loading.attr="disabled" wire:target="fetchAgain">Fetch again</x-ui.button>
                    @endif
                </x-slot:actions>

                <div class="mb-5 flex items-center gap-3">
                    <x-ui.avatar :name="$brand->name" :src="$brand->avatarUrl()" :color="$primaryColor" brand />
                    <div>
                        <span class="lq-cell-title">{{ $brand->name }}</span>
                        <span class="lq-cell-sub">{{ $brand->favicon_path ? 'Favicon fetched from '.parse_url((string) $brand->website, PHP_URL_HOST) : ($brand->website ? 'No favicon found on the website; the initial is used.' : 'No website entered; the initial is used.') }}</span>
                    </div>
                </div>

                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Primary colour" name="primaryColor">
                        <div class="flex items-center gap-2">
                            <input type="color" wire:model.live="primaryColor" class="h-10 w-12 cursor-pointer rounded-lg border-0 bg-transparent p-0">
                            <x-ui.input wire:model="primaryColor" name="primaryColor" class="lq-tabular" />
                        </div>
                    </x-ui.field>
                    <x-ui.field label="Secondary colour" name="secondaryColor">
                        <div class="flex items-center gap-2">
                            <input type="color" wire:model.live="secondaryColor" class="h-10 w-12 cursor-pointer rounded-lg border-0 bg-transparent p-0">
                            <x-ui.input wire:model="secondaryColor" name="secondaryColor" placeholder="None" class="lq-tabular" />
                        </div>
                    </x-ui.field>
                </div>

                <x-slot:footer>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveLook">Next: check</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>
    @endif

    @if ($step === 3 && $brand)
        <x-ui.card title="Check" :description="$brand->name.' is ready. Here is what works and what comes next.'">
            <ul class="lq-steps">
                @foreach ($checks as $check)
                    <li class="lq-step">
                        <span @class(['lq-health-dot', 'lq-health-ok' => $check['ok'] === true, 'lq-health-fail' => $check['ok'] === false, 'lq-health-unknown' => $check['ok'] === null]) style="margin-top: 0.5rem"></span>
                        <div class="lq-step-body">
                            <div class="lq-step-title">{{ $check['label'] }}</div>
                            <p class="lq-step-desc">{{ $check['message'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>

            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="back">Back</x-ui.button>
                <x-ui.button variant="secondary" icon="heroicon-o-globe-alt" :href="route('brand.sites.connect', ['brand' => $brand->slug])">Connect website</x-ui.button>
                <x-ui.button variant="primary" icon="heroicon-o-rectangle-group" :href="route('brand.departments.create', ['brand' => $brand->slug])">Create its first department</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    @endif
</div>
