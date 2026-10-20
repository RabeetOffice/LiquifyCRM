<div class="flex flex-col gap-5">
    <nav class="lq-crumbbar" aria-label="Breadcrumb">
        <x-ui.button size="sm" variant="ghost" icon="heroicon-o-arrow-left" :href="route('brand.leads.index', ['brand' => $brand->slug])">Leads</x-ui.button>
        <span class="lq-muted" aria-hidden="true">/</span>
        <span class="truncate">Add a lead</span>
    </nav>

    <x-ui.page-header title="Add a lead" subtitle="Leads from your websites and Facebook arrive on their own. Use this for calls, walk-ins and referrals." />

    <form wire:submit="save" class="lq-split">
        <div class="lq-split-main">
            <x-ui.card title="Who" description="Their name, and an email or phone if you have one.">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Name" name="name" required>
                        <x-ui.input wire:model="name" name="name" required autofocus />
                    </x-ui.field>
                    <x-ui.field label="Service or package" name="service">
                        <x-ui.input wire:model="service" name="service" placeholder="Publishing" />
                    </x-ui.field>
                    <x-ui.field label="Email" name="email">
                        <x-ui.input type="email" wire:model="email" name="email" />
                    </x-ui.field>
                    <x-ui.field label="Phone" name="phone" help="Any format; it is stored in international form.">
                        <x-ui.input type="tel" wire:model="phone" name="phone" />
                    </x-ui.field>
                </div>
            </x-ui.card>

            <x-ui.card title="Enquiry" description="What they asked for, in their words or yours.">
                <x-ui.field label="Message" name="message">
                    <x-ui.textarea wire:model="message" name="message" rows="6" />
                </x-ui.field>
            </x-ui.card>

            {{-- Now by default; an older enquiry keeps the day it really came in, and is counted on that day. --}}
            <x-ui.card title="When they got in touch" description="Leave it as it is for a call or message from today. For an older enquiry, choose the day and time it came in.">
                <div class="lq-form-grid lq-form-grid-2">
                    <x-ui.field label="Date and time" name="receivedAt" :help="'Your time ('.$zoneCity.').'">
                        <x-ui.input type="datetime-local" wire:model="receivedAt" name="receivedAt" :max="$nowField" />
                    </x-ui.field>
                    <div class="lq-backdate-quick">
                        <span class="lq-muted text-sm">Quick pick</span>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="lq-pickpill" x-on:click="$wire.set('receivedAt', @js($nowField))">Now</button>
                            <button type="button" class="lq-pickpill" x-on:click="$wire.set('receivedAt', @js(\App\Modules\Core\Support\ViewerTime::now()->subDay()->setTime(10, 0)->format('Y-m-d\TH:i')))">Yesterday</button>
                            <button type="button" class="lq-pickpill" x-on:click="$wire.set('receivedAt', @js(\App\Modules\Core\Support\ViewerTime::now()->subWeek()->setTime(10, 0)->format('Y-m-d\TH:i')))">A week ago</button>
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </div>

        <aside class="lq-split-side">
            @if ($canRoute)
                <x-ui.card title="Who gets it" description="Leave empty to put the lead in the New / Unassigned queue.">
                    <div class="lq-form">
                        @if ($isAdmin)
                            <x-ui.field label="Department" name="department">
                                <x-ui.select wire:model.live="department" name="department" placeholder="Not yet">
                                    @foreach ($departmentOptions as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </x-ui.field>
                        @endif
                        <x-ui.field label="Assign to" name="assignTo">
                            <x-ui.select wire:model="assignTo" name="assignTo" placeholder="Nobody yet">
                                @foreach ($agentOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                </x-ui.card>
            @endif

            <section class="lq-card">
                <div class="lq-card-body lq-form">
                    <div>
                        <h2 class="lq-lead-heading">What happens next</h2>
                        <ul class="lq-ticks">
                            <li><x-heroicon-m-check-circle /> The lead is saved as "Added by hand", dated when they got in touch (now, unless you chose an earlier day).</li>
                            <li><x-heroicon-m-check-circle /> If this email or phone is already an open lead here, the enquiry goes to that lead instead of making a second one.</li>
                            <li><x-heroicon-m-check-circle /> The person you assign it to gets a notification.</li>
                        </ul>
                    </div>
                    <x-ui.button type="submit" variant="primary" block icon="heroicon-o-plus" wire:loading.attr="disabled">Add lead</x-ui.button>
                    <x-ui.button variant="ghost" block :href="route('brand.leads.index', ['brand' => $brand->slug])">Cancel</x-ui.button>
                </div>
            </section>
        </aside>
    </form>
</div>
