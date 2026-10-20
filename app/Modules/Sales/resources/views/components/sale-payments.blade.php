{{-- What is in and what is still owed on one sale, with the payments received (19 Oct 2026). A sale paid in one go
     shows nothing more than before. The screen around it uses RecordsPayments for the button. --}}
@props(['sale', 'canPay' => false])
@php
    $ready = \App\Modules\Sales\Support\SalePayments::ready();
    // In the currency the client was charged in (the brand's, unless the sale was in another).
    $due = $sale->due() > 0 ? $sale->dealDue() : 0.0;
    $dealCurrency = $sale->dealCurrency();
    $payments = $ready && $sale->relationLoaded('payments') ? $sale->payments : collect();
@endphp
@if ($ready && ($due > 0 || $payments->count() > 1))
    <div {{ $attributes->class(['lq-paid', 'lq-paid-due' => $due > 0]) }}>
        <div class="lq-paid-line">
            @if ($due > 0)
                <span class="lq-paid-state"><x-heroicon-m-clock /> <span>{{ \App\Modules\Sales\Support\Money::format($sale->dealPaid(), $dealCurrency) }} paid · <strong>{{ \App\Modules\Sales\Support\Money::format($due, $dealCurrency) }} to collect</strong></span></span>
            @else
                <span class="lq-paid-state lq-paid-full"><x-heroicon-m-check-circle /> <span>Paid in full</span></span>
            @endif
            @if ($canPay)
                <x-ui.button size="sm" variant="secondary" icon="heroicon-o-banknotes" wire:click="openPayment({{ $sale->getKey() }})">Record payment</x-ui.button>
            @endif
        </div>
        @if ($payments->isNotEmpty())
            <ul class="lq-paid-list">
                @foreach ($payments as $payment)
                    <li wire:key="payment-{{ $payment->getKey() }}">
                        <span class="lq-tabular">{{ $sale->isForeign() && $payment->charged_amount !== null ? \App\Modules\Sales\Support\Money::format($payment->charged_amount, $dealCurrency).' ('.\App\Modules\Sales\Support\Money::format($payment->amount, $sale->currency).')' : \App\Modules\Sales\Support\Money::format($payment->amount, $sale->currency) }}</span>
                        <span class="lq-muted">{{ $payment->paid_at->format('j M Y') }}{{ $payment->recorder ? ' · '.$payment->recorder->name : '' }}{{ $payment->note ? ' · '.$payment->note : '' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
