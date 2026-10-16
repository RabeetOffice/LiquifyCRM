<div class="flex flex-col gap-6">
    <x-ui.page-header title="Connect website" :breadcrumbs="['Websites' => route('brand.sites.index', ['brand' => $brand->slug]), 'Connect website' => null]" />

    <ol class="lq-wizard">
        @foreach ([1 => 'Add the website', 2 => $site?->isPull() ? 'Where to read leads' : ($site?->usesWebhook() ? 'Paste the address' : 'Upload one file'), 3 => 'Test connection'] as $number => $label)
            <li @class(['lq-wizard-step', 'lq-wizard-active' => $step === $number, 'lq-wizard-done' => $step > $number])>
                @if ($site && $number > 1)
                    <button type="button" wire:click="goTo({{ $number }})"><span class="lq-wizard-mark">@if ($step > $number)<x-heroicon-m-check />@else{{ $number }}@endif</span>{{ $label }}</button>
                @else
                    <span><span class="lq-wizard-mark">@if ($step > $number)<x-heroicon-m-check />@else{{ $number }}@endif</span>{{ $label }}</span>
                @endif
            </li>
        @endforeach
    </ol>

    {{-- Step 1 --}}
    @if ($step === 1)
        <form wire:submit="createSite">
            <x-ui.card title="Add the website" description="LiquifyCRM creates a site key and a secret for it. Leads from this website go to {{ $brand->name }}.">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Website name" name="name" required>
                        <x-ui.input wire:model="name" name="name" placeholder="UK Publishing House" required />
                        <p class="lq-help" wire:loading wire:target="url">Reading the name from the website…</p>
                    </x-ui.field>
                    <x-ui.field label="Website address" name="url" required>
                        <x-ui.input wire:model.blur="url" name="url" placeholder="https://ukpublishinghouse.co.uk" required autofocus />
                    </x-ui.field>
                    <x-ui.field label="How should leads arrive?" name="connection" required class="lq-col-full">
                        <div class="lq-choices">
                            <label @class(['lq-choice', 'lq-choice-active' => $connection === 'php'])>
                                <input type="radio" wire:model.live="connection" value="php">
                                <span class="lq-choice-title">PHP website</span>
                                <span class="lq-choice-desc">Upload one file and paste 2 lines into the form handler. Best choice for your own PHP sites.</span>
                            </label>
                            <label @class(['lq-choice', 'lq-choice-active' => $connection === 'elementor'])>
                                <input type="radio" wire:model.live="connection" value="elementor">
                                <span class="lq-choice-title">WordPress with Elementor</span>
                                <span class="lq-choice-desc">Paste one address into the form's Webhook action. Nothing to upload.</span>
                            </label>
                            <label @class(['lq-choice', 'lq-choice-active' => $connection === 'pull'])>
                                <input type="radio" wire:model.live="connection" value="pull">
                                <span class="lq-choice-title">Do not touch the website</span>
                                <span class="lq-choice-desc">LiquifyCRM reads new rows from the website's own leads table every 5 minutes.</span>
                            </label>
                        </div>
                    </x-ui.field>
                    @if ($connection === 'php')
                        <x-ui.field label="Form handler path" name="formPath" help="The file the form posts to. Used for the form health check.">
                            <x-ui.input wire:model="formPath" name="formPath" placeholder="/form-submission.php" />
                        </x-ui.field>
                    @endif
                </div>

                <x-slot:footer>
                    <x-ui.button variant="ghost" :href="route('brand.sites.index', ['brand' => $brand->slug])">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">Add website</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        </form>
    @endif

    {{-- Step 2 --}}
    @if ($step === 2 && $site)
        @if ($site->isPull())
            <form wire:submit="savePull">
                <x-ui.card title="Where to read leads" description="Nothing changes on {{ $site->domain }}. These details are stored encrypted.">
                    @include('sites::livewire.app.partials.pull-fields')

                    <x-slot:footer>
                        <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">Save and test</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </form>
        @elseif ($site->usesWebhook())
            <x-ui.card title="Paste the address into the form" description="In Elementor: edit the form, open Actions After Submit, add Webhook, and paste this address.">
                <x-ui.field label="Webhook address">
                    <div class="lq-copy" x-data="{ copied: false }">
                        <pre class="lq-log" x-ref="text">{{ $site->webhookUrl() }}</pre>
                        <x-ui.button size="sm" variant="secondary" icon="heroicon-o-clipboard" x-on:click="navigator.clipboard.writeText($refs.text.innerText); copied = true; setTimeout(() => copied = false, 2000)"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></x-ui.button>
                    </div>
                </x-ui.field>
                <p class="lq-help mt-3">Keep this address private: anyone who has it can send leads to this brand. Field names such as Name, Email, Phone and Message are recognised automatically; any other field is kept as an extra field.</p>

                <x-slot:footer>
                    <x-ui.button variant="primary" wire:click="goTo(3)">Next: test connection</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        @else
            <x-ui.card title="Upload one file and paste 2 lines" description="The website only sends the lead. Its own email and database keep working as before.">
                <ol class="lq-steps">
                    <li class="lq-step">
                        <span class="lq-step-mark">1</span>
                        <div class="lq-step-body">
                            <div class="lq-step-title">Download liquify.php</div>
                            <p class="lq-step-desc">The address and secret of {{ $site->domain }} are already filled in. Nothing to edit.</p>
                            <div class="mt-2">
                                <x-ui.button variant="secondary" icon="heroicon-o-arrow-down-tray" :href="route('brand.sites.download', ['brand' => $brand->slug, 'site' => $site])" :navigate="false">Download liquify.php</x-ui.button>
                            </div>
                        </div>
                    </li>
                    <li class="lq-step">
                        <span class="lq-step-mark">2</span>
                        <div class="lq-step-body">
                            <div class="lq-step-title">Upload it into the website's <code class="lq-code">includes/</code> folder</div>
                            <p class="lq-step-desc">With the hosting file manager or FTP.</p>
                        </div>
                    </li>
                    <li class="lq-step">
                        <span class="lq-step-mark">3</span>
                        <div class="lq-step-body">
                            <div class="lq-step-title">Paste these 2 lines into the form handler</div>
                            <p class="lq-step-desc">In <code class="lq-code">{{ ltrim($site->formPath(), '/') }}</code>, right after the line that saves the lead (where <code class="lq-code">$leadId</code> is set), so only leads that passed validation are sent.</p>
                            <div class="lq-copy mt-2" x-data="{ copied: false }">
                                <pre class="lq-log" x-ref="text">{{ $lines }}</pre>
                                <x-ui.button size="sm" variant="secondary" icon="heroicon-o-clipboard" x-on:click="navigator.clipboard.writeText($refs.text.innerText); copied = true; setTimeout(() => copied = false, 2000)"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></x-ui.button>
                            </div>
                        </div>
                    </li>
                </ol>

                <details class="mt-4">
                    <summary class="lq-link text-sm">Optional: a retry cron for websites with few visitors</summary>
                    <p class="lq-help mt-2">A lead that could not be sent waits for the next form submission. To send it sooner, save this as <code class="lq-code">includes/liquify-cron.php</code> and run it every 10 minutes from the hosting panel's Cron Jobs.</p>
                    <pre class="lq-log mt-2">{{ $cron }}</pre>
                </details>

                <x-slot:footer>
                    <x-ui.button variant="primary" wire:click="goTo(3)">Next: test connection</x-ui.button>
                </x-slot:footer>
            </x-ui.card>
        @endif
    @endif

    {{-- Step 3 --}}
    @if ($step === 3 && $site)
        <x-ui.card title="Test connection" description="Checks the website, the file and LiquifyCRM in one go. Then submit the form on the website once.">
            <x-slot:actions>
                <x-sites::connection :site="$site" />
            </x-slot:actions>

            @if ($results === [])
                <p class="text-sm lq-muted">Nothing tested yet.</p>
            @else
                <ul class="lq-steps">
                    @foreach ($results as $result)
                        <li class="lq-step">
                            <span @class(['lq-health-dot', 'lq-health-ok' => $result['ok'] === true, 'lq-health-fail' => $result['ok'] === false, 'lq-health-unknown' => $result['ok'] === null]) style="margin-top: 0.5rem"></span>
                            <div class="lq-step-body">
                                <div class="lq-step-title">{{ $result['label'] }}</div>
                                <p class="lq-step-desc">{{ $result['message'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-slot:footer>
                <x-ui.button variant="ghost" :href="route('brand.sites.show', ['brand' => $brand->slug, 'site' => $site])">Finish</x-ui.button>
                <x-ui.button variant="primary" icon="heroicon-o-bolt" wire:click="test" wire:loading.attr="disabled" wire:target="test">Test connection</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    @endif
</div>
