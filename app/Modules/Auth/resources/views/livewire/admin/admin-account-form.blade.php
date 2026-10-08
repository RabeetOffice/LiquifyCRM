<div class="flex flex-col gap-6">
    <x-ui.page-header :title="$title" :breadcrumbs="['Admin accounts' => route('admin.admins.index'), $title => null]" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-ui.card title="Account">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field label="Name" name="name" required>
                    <x-ui.input wire:model="name" name="name" required autofocus />
                </x-ui.field>
                <x-ui.field label="Email address" name="email" required>
                    <x-ui.input type="email" wire:model="email" name="email" required />
                </x-ui.field>
                <x-ui.field label="Global role" name="role" required help="Super Admins are protected: nobody else can edit, suspend or remove them.">
                    <x-ui.select wire:model="role" name="role" :disabled="$locked">
                        @foreach ($roles as $option)
                            <option value="{{ $option->value }}">{{ $option->getLabel() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Status" name="status" required>
                    <x-ui.select wire:model="status" name="status" :disabled="$locked">
                        @foreach ($statuses as $option)
                            <option value="{{ $option->value }}">{{ $option->getLabel() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.card title="Password" description="At least 12 characters with letters and numbers. Two-factor authentication is required on first sign-in.">
            <div class="lq-form-grid lq-form-grid-2">
                <x-ui.field :label="$account ? 'New password' : 'Password'" name="password" :required="$account === null" :help="$account ? 'Leave empty to keep the current password.' : null">
                    <x-ui.input type="password" wire:model="password" name="password" autocomplete="new-password" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <div class="flex items-center justify-end gap-2">
            <x-ui.button variant="ghost" :href="route('admin.admins.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">{{ $account ? 'Save changes' : 'Create account' }}</x-ui.button>
        </div>
    </form>
</div>
