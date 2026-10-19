<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\Concerns;

use App\Modules\Sales\Actions\RecordPayment;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * "Record payment" under a part-paid sale, on the lead and on the client
 * (19 Oct 2026): the sheet opens with what is still owed, and the payment is
 * checked again on the server (RecordPayment never takes more than is owed).
 * The screen using it shows the sheet with the sales::partials.payment-sheet
 * partial and may redraw itself in paymentRecorded().
 */
trait RecordsPayments
{
    public ?int $paymentSale = null;

    public bool $showPayment = false;

    public string $paymentAmount = '';

    public string $paymentDate = '';

    public string $paymentNote = '';

    public string $paymentCurrency = '';

    public string $paymentDue = '';

    public function openPayment(int $saleId): void
    {
        $sale = $this->payableSale($saleId);

        $this->paymentSale = $saleId;
        $this->paymentAmount = number_format($sale->due(), 2, '.', '');
        $this->paymentDate = now()->toDateString();
        $this->paymentNote = '';
        $this->paymentCurrency = $sale->currency;
        $this->paymentDue = Money::format($sale->due(), $sale->currency);
        $this->resetErrorBag();
        $this->showPayment = true;
    }

    public function recordPayment(): void
    {
        $sale = $this->payableSale((int) $this->paymentSale);

        $data = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'paymentDate' => ['required', 'date', 'before_or_equal:today'],
            'paymentNote' => ['nullable', 'string', 'max:190'],
        ], attributes: ['paymentAmount' => 'amount', 'paymentDate' => 'date', 'paymentNote' => 'note']);

        try {
            app(RecordPayment::class)->handle($sale, (float) $data['paymentAmount'], CarbonImmutable::parse($data['paymentDate'])->setTimeFrom(now()), $this->user(), $data['paymentNote']);
        } catch (InvalidArgumentException $exception) {
            $this->addError('paymentAmount', $exception->getMessage());

            return;
        }

        $sale->refresh();
        $this->showPayment = false;
        $this->paymentRecorded();
        $this->toastSuccess('Payment recorded: '.Money::format((float) $data['paymentAmount'], $sale->currency), $sale->due() > 0.0 ? Money::format($sale->due(), $sale->currency).' still to collect.' : 'This sale is paid in full now.');
    }

    /** After a payment: the screen redraws what depends on it. */
    protected function paymentRecorded(): void {}

    private function payableSale(int $saleId): Sale
    {
        $sale = Sale::query()->findOrFail($saleId);
        $this->authorize('recordPayment', $sale);

        return $sale;
    }
}
