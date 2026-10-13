@php
    $host = parse_url(url('/'), PHP_URL_HOST).(parse_url(url('/'), PHP_URL_PORT) ? ':'.parse_url(url('/'), PHP_URL_PORT) : '');
    $previewName = $brand?->name ?? ($name !== '' ? $name : 'Your brand');
    $websiteHost = $website !== '' ? (parse_url($website, PHP_URL_HOST) ?: $website) : null;
@endphp
<div class="flex flex-col gap-6">
    <x-ui.page-header title="Add brand" :breadcrumbs="['Brands' => route('admin.brands.index'), 'Add brand' => null]"
        subtitle="A brand is a workspace: it owns its websites, Facebook Pages, leads and people." />

    <ol class="lq-wizard">
        @foreach ([1 => 'The basics', 2 => 'The look', 3 => 'The teams', 4 => 'Check'] as $number => $label)
            <li @class(['lq-wizard-step', 'lq-wizard-active' => $step === $number, 'lq-wizard-done' => $step > $number])>
                <span><span class="lq-wizard-mark">@if ($step > $number)<x-heroicon-m-check />@else{{ $number }}@endif</span>{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    {{-- ----------------------------------------------------- 1 basics: the link --}}
    @if ($step === 1 && ! $ready)
        <div class="lq-grid-main">
            <form wire:submit="readWebsite">
                <x-ui.card title="Start with the website" description="Enter the brand's website and LiquifyCRM fills in the rest from it. You check it before anything is saved.">
                    <x-ui.field label="Website" name="website">
                        <div class="relative">
                            <x-heroicon-o-globe-alt class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 lq-muted" />
                            <x-ui.input wire:model="website" name="website" placeholder="ukpublishinghouse.co.uk" autofocus inputmode="url" autocomplete="off" style="padding-inline-start: 2.625rem" />
                        </div>
                        <p class="lq-help flex items-center gap-2" wire:loading.flex wire:target="readWebsite">
                            <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" /> Reading the website…
                        </p>
                    </x-ui.field>

                    <x-slot:footer>
                        <x-ui.button variant="ghost" :href="route('admin.brands.index')">Cancel</x-ui.button>
                        <x-ui.button variant="ghost" wire:click="byHand">No website yet</x-ui.button>
                        <x-ui.button type="submit" variant="primary" icon="heroicon-m-sparkles" wire:loading.attr="disabled" wire:target="readWebsite">Read website</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </form>

            <x-ui.card title="What gets filled in" description="All of it can be changed afterwards.">
                <ul class="lq-ticks">
                    <li><x-heroicon-m-check-circle /><span><strong>Name and link</strong> of the brand</span></li>
                    <li><x-heroicon-m-check-circle /><span><strong>Currency and time zone</strong>, from the country the brand works in</span></li>
                    <li><x-heroicon-m-check-circle /><span><strong>Phone, email and address</strong>, for its invoices and contracts later</span></li>
                    <li><x-heroicon-m-check-circle /><span><strong>Logo, favicon and colour</strong>, in the next step</span></li>
                </ul>
            </x-ui.card>
        </div>
    @endif

    {{-- ------------------------------------------------- 1 basics: check and save --}}
    @if ($step === 1 && $ready)
        <form wire:submit="createBrand" class="lq-grid-main">
            <x-ui.card title="The basics" description="Check what was filled in, change what is not right, then create the brand.">
                <div class="lq-form">
                    <x-ui.field label="Website" name="website" help="Change the link and it is read again. What you typed yourself is never replaced.">
                        <div class="relative">
                            <x-heroicon-o-globe-alt class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 lq-muted" />
                            <x-ui.input wire:model.blur="website" name="website" placeholder="https://ukpublishinghouse.co.uk" inputmode="url" autocomplete="off" style="padding-inline-start: 2.625rem" />
                        </div>
                        <p class="lq-help flex items-center gap-2" wire:loading.flex wire:target="website">
                            <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" /> Reading the website…
                        </p>
                        @if ($websiteNote)
                            <p @class(['lq-fillnote', 'lq-fillnote-warn' => ! $websiteRead]) wire:loading.remove wire:target="website">
                                @if ($websiteRead)<x-heroicon-m-sparkles />@else<x-heroicon-m-exclamation-triangle />@endif
                                <span>{{ $websiteNote }}</span>
                            </p>
                        @endif
                    </x-ui.field>

                    <div class="lq-form-grid lq-form-grid-2">
                        <x-ui.field label="Brand name" name="name" required>
                            <x-ui.input wire:model.blur="name" name="name" placeholder="UK Publishing House" required />
                        </x-ui.field>
                        <x-ui.field label="Link" name="slug" required tip="Where the brand lives in LiquifyCRM. Lowercase letters, numbers and dashes. It is hard to change later because people bookmark it.">
                            <div class="lq-input-group">
                                {{-- The whole link is in the preview: a long host here would leave no room to type. --}}
                                <span class="lq-input-prefix">/b/</span>
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
                        <x-ui.field label="Time zone" name="timezone" required for="timezone" tip="Dates, due times and daily numbers of this brand follow this time zone.">
                            <x-ui.picker model="timezone" name="timezone" :options="$timezones" :pinned="\App\Modules\Core\Support\Timezones::COMMON" placeholder="Search a city or a country…" more="Type a city or a country to see every time zone." />
                        </x-ui.field>
                    </div>

                    <div class="lq-formhead">
                        <h3>Company details</h3>
                        <p>What the brand's invoices and contracts will carry. All optional; add or change them any time under Edit brand.</p>
                    </div>

                    <div class="lq-form-grid lq-form-grid-2">
                        <x-ui.field label="Phone" name="phone">
                            <x-ui.input wire:model.blur="phone" name="phone" placeholder="+44 20 8135 2515" inputmode="tel" />
                        </x-ui.field>
                        <x-ui.field label="Email" name="email">
                            <x-ui.input type="email" wire:model.blur="email" name="email" placeholder="info@ukpublishinghouse.co.uk" />
                        </x-ui.field>
                        <x-ui.field label="Address" name="address">
                            <x-ui.textarea wire:model.blur="address" name="address" rows="4" placeholder="60 Tottenham Court Road&#10;London W1T 2EW&#10;United Kingdom" />
                        </x-ui.field>
                        <div class="lq-form">
                            <x-ui.field label="Registered company name" name="legalName" tip="The name on paper, when it differs from the brand's name: for example UK Publishing House Ltd.">
                                <x-ui.input wire:model.blur="legalName" name="legalName" placeholder="UK Publishing House Ltd" />
                            </x-ui.field>
                            <div class="lq-form-grid lq-form-grid-2">
                                <x-ui.field label="Company number" name="registrationNumber">
                                    <x-ui.input wire:model.blur="registrationNumber" name="registrationNumber" />
                                </x-ui.field>
                                <x-ui.field label="VAT or tax number" name="taxNumber">
                                    <x-ui.input wire:model.blur="taxNumber" name="taxNumber" />
                                </x-ui.field>
                            </div>
                        </div>
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
                        <x-ui.avatar :name="$previewName" :src="$foundIcon" tile="#FFFFFF" size="lg" brand wire:key="icon-{{ md5((string) $foundIcon) }}" />
                        <div class="min-w-0">
                            <div class="lq-cell-title truncate">{{ $previewName }}</div>
                            <div class="lq-cell-sub truncate">{{ $host }}/b/{{ $slug !== '' ? $slug : '…' }}</div>
                        </div>
                    </div>
                    <dl class="lq-dl">
                        <div><dt>Sales recorded in</dt><dd>{{ $currency }}</dd></div>
                        <div><dt>Time zone</dt><dd class="truncate" x-text="$wire.timezone.split('/').pop().replaceAll('_', ' ')">{{ str_replace('_', ' ', \Illuminate\Support\Str::afterLast($timezone, '/')) }}</dd></div>
                        <div><dt>Website</dt><dd class="truncate">{{ $websiteHost ?? 'Not entered' }}</dd></div>
                    </dl>
                    @if ($foundLogo)
                        <div>
                            <div class="mb-1.5 text-xs lq-muted">Logo found on the website</div>
                            <div class="lq-logo-strip" wire:key="logo-{{ md5($foundLogo) }}">
                                <img src="{{ $foundLogo }}" alt="" referrerpolicy="no-referrer" onerror="this.closest('div').parentNode.remove()">
                            </div>
                        </div>
                    @endif
                </div>
                <p class="lq-help mt-4">Next: the logo, favicon and colour are brought in from the website, and you can adjust them.</p>
            </x-ui.card>
        </form>
    @endif

    {{-- -------------------------------------------------------------- 2 look --}}
    @if ($step === 2 && $brand)
        @php
            $found = array_filter([$brand->logo_path ? 'logo' : null, $brand->favicon_path ? 'favicon' : null, $brand->primaryColor() ? 'colour' : null]);
            $shownLogo = $logo?->isPreviewable() ? $logo->temporaryUrl() : $brand->logoUrl();
        @endphp
        <form wire:submit="saveLook" class="lq-grid-main">
            <x-ui.card title="The look" description="The brand's own mark and colour make it recognisable at a glance. LiquifyCRM's own buttons stay orange.">
                <x-slot:actions>
                    @if ($brand->website)
                        <x-ui.button variant="secondary" size="sm" icon="heroicon-o-arrow-path" wire:click="fetchAgain" wire:loading.attr="disabled" wire:target="fetchAgain">Fetch again</x-ui.button>
                    @endif
                </x-slot:actions>

                <div class="lq-form">
                    <div @class(['lq-alert', 'lq-alert-success' => $found !== [], 'lq-alert-warning' => $found === []])>
                        @if ($found !== [])
                            <x-heroicon-o-check-circle />
                            <span>Read from <strong>{{ parse_url((string) $brand->website, PHP_URL_HOST) }}</strong>: {{ implode(', ', $found) }}. {{ $brand->logo_path ? 'Adjust anything that is not right.' : 'No usable logo was found (a white one, made for a dark page, is left out): upload one below.' }}</span>
                        @else
                            <x-heroicon-o-information-circle />
                            <span>{{ $brand->website ? 'Nothing could be read from the website, so the brand shows its first letter. Upload a logo and pick its colour below.' : 'No website was entered, so the brand shows its first letter. Upload a logo and pick its colour below.' }}</span>
                        @endif
                    </div>

                    <x-ui.field label="Logo" name="logo" help="Shown on the brand's own pages and, later, on its invoices and contracts. A dark logo on a see-through background works best. Up to 2 MB.">
                        <div class="lq-logo-row">
                            <div class="lq-logo-strip">
                                @if ($shownLogo)
                                    <img src="{{ $shownLogo }}" alt="{{ $brand->name }} logo">
                                @else
                                    <span>No logo yet</span>
                                @endif
                            </div>
                            <input type="file" class="lq-input lq-input-file" wire:model="logo" name="logo" id="logo" accept="image/png,image/jpeg,image/webp,image/gif">
                        </div>
                        <p class="lq-help" wire:loading wire:target="logo">Uploading…</p>
                    </x-ui.field>

                    <div class="lq-form-grid lq-form-grid-2">
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
                </div>

                <x-slot:footer>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveLook, logo">Next: the teams</x-ui.button>
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card title="Preview" description="Updates as you change the colours.">
                <div class="lq-brand-preview">
                    <div class="lq-brand-preview-row">
                        <x-ui.avatar :name="$brand->name" :src="$brand->avatarUrl()" :color="$accent" :tile="$tile" size="lg" brand />
                        <div class="min-w-0">
                            <div class="lq-cell-title truncate">{{ $brand->name }}</div>
                            <div class="lq-cell-sub truncate">{{ $host }}/b/{{ $brand->slug }}</div>
                        </div>
                    </div>

                    <div class="lq-brand-sample" style="--brand-color: {{ $accent ?? '#FF6B2C' }}">
                        <div class="mb-1.5 flex items-center justify-between text-xs lq-muted"><span>Its bar in charts</span><span class="lq-tabular">64%</span></div>
                        <div class="lq-progress">
                            <div class="lq-progress-bar" style="width: 64%"></div>
                        </div>
                        <span class="lq-badge mt-3">{{ $brand->name }}</span>
                    </div>
                </div>
                @unless ($accent)
                    <p class="lq-help mt-4">No colour of its own yet, so the brand shows in LiquifyCRM's orange. Pick a second colour to give it one.</p>
                @endunless
            </x-ui.card>
        </form>
    @endif

    {{-- ------------------------------------------------------------- 3 teams --}}
    @if ($step === 3 && $brand)
        @php
            $open = array_filter($starters, fn (array $team): bool => ! in_array($team['name'], $existing, true));
        @endphp
        <form wire:submit="createTeams" class="lq-grid-main">
            <x-ui.card title="The teams" description="The departments most brands start with. Untick what this brand does not need: names, people and more departments can be changed later.">
                @if ($existing !== [])
                    <div class="lq-alert lq-alert-success mb-4">
                        <x-heroicon-o-check-circle />
                        <span>{{ $brand->name }} already has: <strong>{{ implode(', ', $existing) }}</strong>.</span>
                    </div>
                @endif

                @if ($open !== [])
                    <div class="lq-choices">
                        @foreach ($open as $key => $team)
                            <label class="lq-choice lq-team" :class="$wire.teams.includes('{{ $key }}') && 'lq-choice-active'" wire:key="team-{{ $key }}">
                                <input type="checkbox" wire:model="teams" value="{{ $key }}">
                                <span class="lq-team-head">
                                    <span class="lq-team-dot" style="background: {{ $team['color'] }}"></span>
                                    <span class="lq-choice-title">{{ $team['name'] }}</span>
                                    <x-heroicon-m-check-circle class="lq-team-tick" />
                                </span>
                                <span class="lq-choice-desc">{{ $team['description'] }}</span>
                                <span class="lq-team-kind">{{ $team['kind'] === \App\Modules\Core\Enums\DepartmentKind::Sales ? 'Works leads' : 'Works clients' }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif

                <x-slot:footer>
                    <x-ui.button variant="ghost" wire:click="back">Back</x-ui.button>
                    @if ($open !== [])
                        <x-ui.button variant="ghost" wire:click="skipTeams">Skip for now</x-ui.button>
                        <x-ui.button type="submit" variant="primary" icon="heroicon-o-rectangle-group" wire:loading.attr="disabled" wire:target="createTeams" x-bind:disabled="$wire.teams.length === 0">Create departments</x-ui.button>
                    @else
                        <x-ui.button variant="primary" wire:click="skipTeams">Next: check</x-ui.button>
                    @endif
                </x-slot:footer>
            </x-ui.card>

            <x-ui.card title="How they work together" description="The path of every lead in this brand.">
                <ol class="lq-guide">
                    <li class="lq-guide-step lq-guide-done">
                        <span class="lq-guide-mark">1</span>
                        <div>
                            <h3 class="lq-guide-title">Front Sales wins the lead</h3>
                            <p class="lq-guide-text">New leads from the website and Facebook are worked until the first sale.</p>
                        </div>
                    </li>
                    <li class="lq-guide-step lq-guide-done">
                        <span class="lq-guide-mark">2</span>
                        <div>
                            <h3 class="lq-guide-title">The client is handed over</h3>
                            <p class="lq-guide-text">On Mark as Won the seller hands the client to a team that works clients, or to one person in it.</p>
                        </div>
                    </li>
                    <li class="lq-guide-step lq-guide-done">
                        <span class="lq-guide-mark">3</span>
                        <div>
                            <h3 class="lq-guide-title">Upsell and project managers take over</h3>
                            <p class="lq-guide-text">They run the work and record every later sale, until the client is closed.</p>
                        </div>
                    </li>
                </ol>
            </x-ui.card>
        </form>
    @endif

    {{-- ------------------------------------------------------------- 4 check --}}
    @if ($step === 4 && $brand)
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
                <x-ui.button variant="ghost" :href="route('admin.brands.edit', ['brandId' => $brand->getKey()])">Edit brand</x-ui.button>
                <x-ui.button variant="secondary" icon="heroicon-o-globe-alt" :href="route('brand.sites.connect', ['brand' => $brand->slug])">Connect website</x-ui.button>
                @if ($hasDepartments)
                    <x-ui.button variant="primary" icon="heroicon-o-user-plus" :href="route('brand.members.create', ['brand' => $brand->slug])">Add its people</x-ui.button>
                @else
                    <x-ui.button variant="primary" icon="heroicon-o-rectangle-group" :href="route('brand.departments.create', ['brand' => $brand->slug])">Create its first department</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.card>
    @endif
</div>
