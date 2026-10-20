<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Support\ExchangeRates;
use App\Modules\Sales\Support\Money;
use App\Modules\Sales\Support\SalePayments;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Money received for a sale (user request, 19 Oct 2026). A client may pay
 * part of the deal when it is closed and the rest later: the sale keeps the
 * whole deal (that is what counts towards the targets, the user's choice),
 * and each payment adds to what is in, never more than what is still owed.
 * A payment is not a new sale, so nothing is counted twice. For a sale in
 * another currency than the brand's, a payment is typed in that currency and
 * turned into the brand's at the sale's own rate, so paying the whole deal
 * always settles it exactly.
 */
final class RecordPayment
{
    /**
     * @param  float  $amount  in the currency the client was charged in (the brand's, unless the sale was in another)
     *
     * @throws InvalidArgumentException when the sale is paid in full or the amount is more than is owed
     */
    public function handle(Sale $sale, float $amount, CarbonInterface $paidAt, User $by, ?string $note = null): SalePayment
    {
        return DB::transaction(function () use ($sale, $amount, $paidAt, $by, $note): SalePayment {
            /** @var Sale $sale */
            $sale = Sale::query()->acrossBrands()->lockForUpdate()->findOrFail($sale->getKey());
            $due = $sale->dealDue();
            $currency = $sale->dealCurrency();

            if ($due <= 0.0 || $sale->due() <= 0.0) {
                throw new InvalidArgumentException('This sale is paid in full.');
            }

            if ($amount <= 0.0 || round($amount, 2) > $due) {
                throw new InvalidArgumentException('That is more than the '.Money::format($due, $currency).' still to collect.');
            }

            // In the brand's currency: the rest of the deal exactly when it is all paid, else at the sale's rate.
            $inBrand = ! $sale->isForeign() ? $amount : (round($amount, 2) >= $due ? $sale->due() : min(ExchangeRates::convert($amount, (float) $sale->exchange_rate), $sale->due()));

            $payment = $this->add($sale, $inBrand, $paidAt, $by, $note, $sale->isForeign() ? round($amount, 2) : null);
            $left = $sale->dealDue();

            Audit::log(
                'sales.payment_recorded',
                $this->subject($sale),
                ['sale_id' => $sale->getKey(), 'payment_id' => $payment->getKey(), 'amount' => $amount, 'currency' => $currency, 'paid_at' => $paidAt->toDateString(), 'still_due' => $left],
                'Payment received: '.Money::format($amount, $currency).($left > 0.0 ? ' · '.Money::format($left, $currency).' still to collect' : ' · paid in full'),
                brand: $sale->brand_id,
                causer: $by,
                logName: 'sales',
            );

            return $payment;
        });
    }

    /**
     * What was paid when the sale was recorded: the whole deal unless less was
     * given, nothing written when nothing was paid yet.
     */
    /** @param  float|null  $chargedPaid  the same in the currency the client was charged in, for a sale in another currency */
    public function opening(Sale $sale, ?float $paidNow, CarbonInterface $paidAt, User $by, ?float $chargedPaid = null): void
    {
        if (! SalePayments::ready()) {
            return;
        }

        $paid = $paidNow === null ? (float) $sale->amount : min(max(round($paidNow, 2), 0.0), (float) $sale->amount);
        $charged = $sale->isForeign() ? ($chargedPaid ?? (float) $sale->charged_amount) : null;

        if ($paid > 0.0) {
            $this->add($sale, $paid, $paidAt, $by, null, $charged);
        } else {
            $sale->forceFill(['amount_paid' => 0, ...($sale->isForeign() ? ['charged_paid' => 0] : [])])->save();
        }
    }

    private function add(Sale $sale, float $amount, CarbonInterface $paidAt, User $by, ?string $note, ?float $charged = null): SalePayment
    {
        $payment = SalePayment::query()->create([
            'brand_id' => $sale->brand_id,
            'sale_id' => $sale->getKey(),
            'amount' => $amount,
            ...($charged !== null ? ['charged_amount' => $charged] : []),
            'paid_at' => $paidAt,
            'note' => filled($note) ? trim((string) $note) : null,
            'recorded_by' => $by->getKey(),
        ]);

        $payments = SalePayment::query()->acrossBrands()->where('sale_id', $sale->getKey());

        $sale->forceFill([
            'amount_paid' => (clone $payments)->sum('amount'),
            ...($sale->isForeign() ? ['charged_paid' => (clone $payments)->sum('charged_amount')] : []),
        ])->save();

        return $payment;
    }

    /** A front sale's payments go on its lead's timeline; an upsell has no lead, so on the client's. */
    private function subject(Sale $sale): Lead|Client|Sale
    {
        $lead = $sale->lead_id !== null ? Lead::query()->acrossBrands()->find($sale->lead_id) : null;

        return $lead ?? Client::query()->acrossBrands()->find($sale->client_id) ?? $sale;
    }
}
