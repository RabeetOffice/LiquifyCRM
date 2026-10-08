<div class="flex flex-col gap-6">
    <x-ui.page-header title="Add person" :breadcrumbs="['Members' => route('brand.members.index', ['brand' => $brand->slug]), 'Add person' => null]" subtitle="Create the login with a password here, or invite by email from the Members screen." />

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-ui.card title="Person" description="If this email already has a LiquifyCRM account, that account is added to the brand and the password is left unchanged.">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Name" name="name" required>
                    <x-ui.input wire:model="name" name="name" required autofocus />
                </x-ui.field>
                <x-ui.field label="Email address" name="email" required>
                    <x-ui.input type="email" wire:model="email" name="email" required />
                </x-ui.field>
                <x-ui.field label="Password" name="password" required help="At least 10 characters with letters and numbers. Share it with them securely." class="lq-col-full">
                    <x-ui.input type="password" wire:model="password" name="password" autocomplete="new-password" required />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Access">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Role in this brand" name="role" required>
                    <x-ui.select wire:model="role" name="role">
                        @foreach ($roleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Department" name="department" :required="! auth()->user()->isGlobalAdmin()">
                    <x-ui.select wire:model="department" name="department" placeholder="Choose later">
                        @foreach ($departmentOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Monthly revenue target ({{ $brand->currency }})" name="targetRevenue" help="For {{ now()->format('F') }}. Leave empty for none; change it any time under Targets.">
                    <x-ui.input type="number" min="0" step="1" inputmode="decimal" wire:model="targetRevenue" name="targetRevenue" />
                </x-ui.field>
                <x-ui.field label="Monthly sales target" name="targetCount" help="Number of sales.">
                    <x-ui.input type="number" min="0" step="1" inputmode="numeric" wire:model="targetCount" name="targetCount" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('brand.members.index', ['brand' => $brand->slug])">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">Add person</x-ui.button>
        </div>
    </form>
</div>
