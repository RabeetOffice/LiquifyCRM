{{-- "Edit details" of a lead, for a screen that uses the EditsLeadDetails trait. --}}
<x-ui.modal model="showDetails" title="Edit details" description="What you learn about this person while you talk. It stays on the lead and goes with it when it becomes a client.">
    <form wire:submit="saveDetails" class="lq-form" id="lead-details-form">
        <x-ui.field label="Name" name="details.name" required>
            <x-ui.input wire:model="details.name" name="details.name" autocomplete="off" />
        </x-ui.field>

        <div class="lq-form-grid lq-form-grid-2">
            <x-ui.field label="Phone" name="details.phone" :help="$detailsContactVisible ? null : 'Hidden. Type a number only to replace it.'">
                <x-ui.input type="tel" wire:model="details.phone" name="details.phone" autocomplete="off" />
            </x-ui.field>
            <x-ui.field label="Email" name="details.email" :help="$detailsContactVisible ? null : 'Hidden. Type an email only to replace it.'">
                <x-ui.input type="email" wire:model="details.email" name="details.email" autocomplete="off" />
            </x-ui.field>
            <x-ui.field label="Company" name="details.company">
                <x-ui.input wire:model="details.company" name="details.company" autocomplete="off" />
            </x-ui.field>
            <x-ui.field label="Service" name="details.service" help="What they are asking for.">
                <x-ui.input wire:model="details.service" name="details.service" autocomplete="off" />
            </x-ui.field>
        </div>

        <x-ui.field label="Address" name="details.address">
            <x-ui.textarea wire:model="details.address" name="details.address" rows="2" />
        </x-ui.field>
    </form>
    <x-slot:footer>
        <x-ui.button variant="ghost" wire:click="$set('showDetails', false)">Cancel</x-ui.button>
        <x-ui.button type="submit" form="lead-details-form" variant="primary" wire:loading.attr="disabled" wire:target="saveDetails">Save details</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
