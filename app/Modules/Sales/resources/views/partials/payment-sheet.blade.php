{{-- The RecordsPayments sheet: money received later for a part-paid sale. --}}
<x-ui.modal model="showPayment" title="Record a payment" :description="$paymentDue !== '' ? $paymentDue.' is still to collect on this sale. A payment is not a new sale: the targets stay as they are.' : null" size="sm">
    <form wire:submit="recordPayment" class="lq-form" id="payment-form">
        <x-ui.field label="Amount received" name="paymentAmount" required>
            <div class="lq-input-group">
                <span class="lq-input-prefix">{{ $paymentCurrency }}</span>
                <x-ui.input type="number" step="0.01" min="0" wire:model="paymentAmount" name="paymentAmount" inputmode="decimal" />
            </div>
        </x-ui.field>
        <x-ui.field label="Received on" name="paymentDate" required>
            <x-ui.input type="date" wire:model="paymentDate" name="paymentDate" max="{{ now()->toDateString() }}" />
        </x-ui.field>
        <x-ui.field label="Note" name="paymentNote" help="Optional: the bank transfer, the invoice number, the second part…">
            <x-ui.input wire:model="paymentNote" name="paymentNote" />
        </x-ui.field>
    </form>
    <x-slot:footer>
        <x-ui.button variant="ghost" wire:click="$set('showPayment', false)">Cancel</x-ui.button>
        <x-ui.button type="submit" form="payment-form" variant="primary" wire:loading.attr="disabled" wire:target="recordPayment">Record payment</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
