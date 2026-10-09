@php
    $host = parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '');
    $previewName = $brand?->name ?? ($name !== '' ? $name : 'Your brand');
    $previewInitial = mb_strtoupper(mb_substr($previewName, 0, 1));
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header title="Add brand" :breadcrumbs="['Brands' => route('admin.brands.index'), 'Add brand' => null]"
        subtitle="A brand is a workspace: it owns its websites, Facebook Pages, leads and people." />

    <ol class="lq-wizard">
        @foreach ([1 => 'The basics', 2 => 'The look', 3 => 'Check'] as $number => $label)
            <li @class(['lq-wizard-step', 'lq-wizard-active' => $step === $number, 'lq-wizard-done' => $step > $number])>
                <span><span class="lq-wizard-mark">@if ($step > $number)<x-heroicon-m-check />@else{{ $number }}@endif</span>{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    {{-- ------------------------------------------------------------ 1 basics --}}
    @if ($step === 1)
        <form wire:submit="createBrand" class="lq-grid-main">
            <x-ui.card title="The basics" description="Paste the website link first: the name and address fill themselves in.">
                <div class="lq-form">
                    <x-ui.field label="Website" name="website" help="The favicon and brand colours are read from here in the next step.">
                        <div class="relative">
                            <x-heroicon-o-globe-alt class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 lq-muted" />
                            <x-ui.input wire:model.blur="website" name="website" placeholder="https://ukpublishinghouse.co.uk" autofocus inputmode="url" style="padding-inline-start: 2.625rem" />
                        </div>
                        <p class="lq-help flex items-center gap-2" wire:loading wire:target="website">
                            <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" /> Reading the website…
                        </p>
                        @if ($websiteNote)
                            <p class="lq-help flex items-center gap-2" style="color: var(--lq-success)" wire:loading.remove wire:target="website">
                                <x-heroicon-m-sparkles class="h-4 w-4 flex-none" /> {{ $websiteNote }}
                            </p>
                        @endif
                    </x-ui.field>

                    <div class="lq-form-grid lq-form-grid-2">
                        <x-ui.field label="Brand name" name="name" required>
                            <x-ui.input wire:model.blur="name" name="name" placeholder="UK Publishing House" required />
                        </x-ui.field>
                        <x-ui.field label="Address" name="slug" required tip="The short name in the address bar. Lowercase letters, numbers and dashes. It is hard to change later because people bookmark it.">
                            <div class="lq-input-group">
                                <span class="lq-input-prefix">{{ $host }}/b/</span>
                                <x-ui.input wire:model.blur="slug" name="slug" required placeholder="uk-publishing-house" />
                            </div>
                        </x-ui.field>
                        <x-ui.field label="Currency" name="currency" required tip="Every sale and target in this brand is recorded in this currency.">
                            <x-ui.select wire:model.live="currency" name="currency">
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
                </div>

                <x-slot:footer>
                    <x-ui.button variant="ghost" :href="route('admin.brands.index')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createBrand, website">Create brand</x-ui.button>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card title="Preview" description="How the brand appears in the switcher.">
                <div class="lq-brand-preview">
                    <div class="lq-brand-preview-row">
                        <span class="lq-brand-preview-mark" style="--brand-color: {{ $primaryColor }}">{{ $previewInitial }}</span>
                        <div class="min-w-0">
                            <div class="lq-cell-title truncate">{{ $previewName }}</div>
                            <div class="lq-cell-sub truncate">{{ $host }}/b/{{ $slug !== '' ? $slug : '…' }}</div>
                        </div>
                    </div>
                    <dl class="lq-dl">
                        <div><dt>Sales recorded in</dt><dd>{{ $currency }}</dd></div>
                        <div><dt>Website</dt><dd class="truncate">{{ $website !== '' ? parse_url($website, PHP_URL_HOST) ?: $website : 'Not entered' }}</dd></div>
                    </dl>
                </div>
                <p class="lq-help mt-4">Next: the favicon and colours are fetched from the website, and you can adjust them.</p>
            </x-ui.card>
        </form>
    @endif

    {{-- -------------------------------------------------------------- 2 look --}}
    @if ($step === 2 && $brand)
        <form wire:submit="saveLook" class="lq-grid-main">
            <x-ui.card title="The look" description="The brand's own mark and colours make it recognisable at a glance. LiquifyCRM's own buttons stay orange.">
                <x-slot:actions>
                    @if ($brand->website)
                        <x-ui.button variant="secondary" size="sm" icon="heroicon-o-arrow-path" wire:click="fetchAgain" wire:loading.attr="disabled" wire:target="fetchAgain">Fetch again</x-ui.button>
                    @endif
                </x-slot:actions>

                <div class="lq-form">
                    <div @class(['lq-alert', 'lq-alert-success' => $brand->favicon_path !== null, 'lq-alert-warning' => $brand->favicon_path === null])>
                        @if ($brand->favicon_path)
                            <x-heroicon-o-check-circle />
                            <span>Favicon and colours were read from <strong>{{ parse_url((string) $brand->website, PHP_URL_HOST) }}</strong>. Adjust the colours below if they are not right.</span>
                        @else
                            <x-heroicon-o-information-circle />
                            <span>{{ $brand->website ? 'No favicon was found on the website, so the brand shows its first letter. You can upload a logo later under Edit brand.' : 'No website was entered, so the brand shows its first letter. Pick its colours below.' }}</span>
                        @endif
                    </div>

                    <div class="lq-form-grid lq-form-grid-2">
                        <x-ui.field label="Main colour" name="primaryColor" help="The brand's chip and its line in charts.">
                            <div class="lq-swatch">
                                <input type="color" wire:model.live="primaryColor" aria-label="Main colour">
                                <input type="text" class="lq-swatch-text" wire:model.blur="primaryColor" name="primaryColor" maxlength="7" placeholder="#FF6B2C">
                            </div>
                        </x-ui.field>
                        <x-ui.field label="Second colour" name="secondaryColor" help="Optional. Used as an accent next to the main colour.">
                            <div class="lq-swatch">
                                <input type="color" wire:model.live="secondaryColor" aria-label="Second colour" value="{{ $secondaryColor !== '' ? $secondaryColor : '#ffffff' }}">
                                <input type="text" class="lq-swatch-text" wire:model.blur="secondaryColor" name="secondaryColor" maxlength="7" placeholder="None">
                            </div>
                        </x-ui.field>
                    </div>
                </div>

                <x-slot:footer>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveLook">Next: check</x-ui.button>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card title="Preview" description="Updates as you change the colours.">
                <div class="lq-brand-preview">
                    <div class="lq-brand-preview-row">
                        <span class="lq-brand-preview-mark" style="--brand-color: {{ $primaryColor !== '' ? $primaryColor : '#FF6B2C' }}">
                            @if ($brand->avatarUrl())
                                <img src="{{ $brand->avatarUrl() }}" alt="">
                            @else
                                {{ $previewInitial }}
                            @endif
                        </span>
                        <div class="min-w-0">
                            <div class="lq-cell-title truncate">{{ $brand->name }}</div>
                            <div class="lq-cell-sub truncate">{{ $host }}/b/{{ $brand->slug }}</div>
                        </div>
                    </div>

                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-xs lq-muted"><span>Its bar in charts</span><span class="lq-tabular">64%</span></div>
                        <div class="lq-progress" style="background: color-mix(in srgb, {{ $primaryColor !== '' ? $primaryColor : '#FF6B2C' }} 18%, transparent)">
                            <div class="lq-progress-bar" style="width: 64%; background: {{ $primaryColor !== '' ? $primaryColor : '#FF6B2C' }}"></div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="lq-badge" style="background: color-mix(in srgb, {{ $primaryColor !== '' ? $primaryColor : '#FF6B2C' }} 16%, transparent); color: {{ $primaryColor !== '' ? $primaryColor : '#FF6B2C' }}">{{ $brand->name }}</span>
                        @if ($secondaryColor !== '')
                            <span class="lq-badge" style="background: color-mix(in srgb, {{ $secondaryColor }} 16%, transparent); color: {{ $secondaryColor }}">Accent</span>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        </form>
    @endif

    {{-- ------------------------------------------------------------- 3 check --}}
    @if ($step === 3 && $brand)
        <x-ui.card :title="$brand->name.' is ready'" description="Here is what works already and what comes next.">
            <ol class="lq-guide">
                @foreach ($checks as $check)
                    <li @class(['lq-guide-step', 'lq-guide-done' => $check['ok'] === true, 'lq-guide-current' => $check['ok'] === false])>
                        <span class="lq-guide-mark">
                            @if ($check['ok'] === true)
                                <x-heroicon-m-check />
                            @elseif ($check['ok'] === false)
                                <x-heroicon-m-exclamation-triangle />
                            @else
                                <x-heroicon-m-minus />
                            @endif
                        </span>
                        <div>
                            <h3 class="lq-guide-title">{{ $check['label'] }}</h3>
                            <p class="lq-guide-text">{{ $check['message'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>

            <x-slot:footer>
                <x-ui.button variant="ghost" wire:click="back">Back</x-ui.button>
                <x-ui.button variant="secondary" icon="heroicon-o-globe-alt" :href="route('brand.sites.connect', ['brand' => $brand->slug])">Connect website</x-ui.button>
                <x-ui.button variant="primary" icon="heroicon-o-rectangle-group" :href="route('brand.departments.create', ['brand' => $brand->slug])">Create its first department</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    @endif
</div>
