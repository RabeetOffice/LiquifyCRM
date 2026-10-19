{{-- The CreatesInvoices sheet: filled in from the client and the sale, checked, then the PDF downloads. --}}
<x-ui.modal model="showInvoice" title="Create invoice" description="Check who it is for. The amount, what is paid and the brand's company and bank details are added by themselves; the PDF downloads and is kept in the client's files." size="md">
    <form wire:submit="createInvoice" class="lq-form" id="invoice-form">
        <x-ui.field label="Bill to" name="invoiceName" required>
            <x-ui.input wire:model="invoiceName" name="invoiceName" />
        </x-ui.field>
        <div class="lq-form-grid lq-form-grid-2">
            <x-ui.field label="Email" name="invoiceEmail">
                <x-ui.input type="email" wire:model="invoiceEmail" name="invoiceEmail" />
            </x-ui.field>
            <x-ui.field label="Phone" name="invoicePhone">
                <x-ui.input wire:model="invoicePhone" name="invoicePhone" inputmode="tel" />
            </x-ui.field>
        </div>
        @if ($invoiceMasked)
            <p class="lq-help">Their email and phone are masked for you. Reveal them on the lead to have them filled in, or leave them out.</p>
        @endif
        <x-ui.field label="Address" name="invoiceAddress">
            <textarea class="lq-input" rows="2" wire:model="invoiceAddress" name="invoiceAddress" id="invoiceAddress"></textarea>
        </x-ui.field>
        <x-ui.field label="What it is for" name="invoiceItem" required>
            <x-ui.input wire:model="invoiceItem" name="invoiceItem" />
        </x-ui.field>
        <div class="lq-form-grid lq-form-grid-2">
            <x-ui.field label="Invoice date" name="invoiceDate" required>
                <x-ui.input type="date" wire:model="invoiceDate" name="invoiceDate" />
            </x-ui.field>
            <x-ui.field label="Due by" name="invoiceDue">
                <x-ui.input type="date" wire:model="invoiceDue" name="invoiceDue" />
            </x-ui.field>
        </div>
        <x-ui.field label="Note" name="invoiceNote">
            <textarea class="lq-input" rows="2" wire:model="invoiceNote" name="invoiceNote" id="invoiceNote"></textarea>
        </x-ui.field>
    </form>
    <x-slot:footer>
        <x-ui.button variant="ghost" wire:click="$set('showInvoice', false)">Cancel</x-ui.button>
        <x-ui.button type="submit" form="invoice-form" variant="primary" icon="heroicon-o-arrow-down-tray" wire:loading.attr="disabled" wire:target="createInvoice">Create and download</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
