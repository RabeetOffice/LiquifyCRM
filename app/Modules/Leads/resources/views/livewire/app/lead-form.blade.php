<div class="flex flex-col gap-6">
    <x-ui.page-header title="Add a lead" :breadcrumbs="['Leads' => route('brand.leads.index', ['brand' => $brand->slug]), 'Add a lead' => null]" subtitle="Leads from your websites and Facebook arrive on their own. Use this for calls, walk-ins and referrals." />

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-ui.card title="Who">
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

        <x-ui.card title="Enquiry">
            <x-ui.field label="Message" name="message">
                <x-ui.textarea wire:model="message" name="message" rows="4" />
            </x-ui.field>
        </x-ui.card>

        @if ($canRoute)
            <x-ui.card title="Routing" description="Leave empty to put the lead in the New / Unassigned queue.">
                <div class="lq-form-grid lq-form-grid-2">
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

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('brand.leads.index', ['brand' => $brand->slug])">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">Add lead</x-ui.button>
        </div>
    </form>
</div>
