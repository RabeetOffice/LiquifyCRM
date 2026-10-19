<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
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
 * A payment is not a new sale, so nothing is counted twice.
 */
final class RecordPayment
{
    /** @throws InvalidArgumentException when the sale is paid in full or the amount is more than is owed */
    public function handle(Sale $sale, float $amount, CarbonInterface $paidAt, User $by, ?string $note = null): SalePayment
    {
        return DB::transaction(function () use ($sale, $amount, $paidAt, $by, $note): SalePayment {
            /** @var Sale $sale */
            $sale = Sale::query()->acrossBrands()->lockForUpdate()->findOrFail($sale->getKey());
            $due = $sale->due();

            if ($due <= 0.0) {
                throw new InvalidArgumentException('This sale is paid in full.');
            }

            if ($amount <= 0.0 || round($amount, 2) > $due) {
                throw new InvalidArgumentException('That is more than the '.Money::format($due, $sale->currency).' still to collect.');
            }

            $payment = $this->add($sale, $amount, $paidAt, $by, $note);
            $left = $sale->due();

            Audit::log(
                'sales.payment_recorded',
                $this->subject($sale),
                ['sale_id' => $sale->getKey(), 'payment_id' => $payment->getKey(), 'amount' => $amount, 'currency' => $sale->currency, 'paid_at' => $paidAt->toDateString(), 'still_due' => $left],
                'Payment received: '.Money::format($amount, $sale->currency).($left > 0.0 ? ' · '.Money::format($left, $sale->currency).' still to collect' : ' · paid in full'),
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
    public function opening(Sale $sale, ?float $paidNow, CarbonInterface $paidAt, User $by): void
    {
        if (! SalePayments::ready()) {
            return;
        }

        $paid = $paidNow === null ? (float) $sale->amount : min(max(round($paidNow, 2), 0.0), (float) $sale->amount);

        if ($paid > 0.0) {
            $this->add($sale, $paid, $paidAt, $by, null);
        } else {
            $sale->forceFill(['amount_paid' => 0])->save();
        }
    }

    private function add(Sale $sale, float $amount, CarbonInterface $paidAt, User $by, ?string $note): SalePayment
    {
        $payment = SalePayment::query()->create([
            'brand_id' => $sale->brand_id,
            'sale_id' => $sale->getKey(),
            'amount' => $amount,
            'paid_at' => $paidAt,
            'note' => filled($note) ? trim((string) $note) : null,
            'recorded_by' => $by->getKey(),
        ]);

        $sale->forceFill(['amount_paid' => SalePayment::query()->acrossBrands()->where('sale_id', $sale->getKey())->sum('amount')])->save();

        return $payment;
    }

    /** A front sale's payments go on its lead's timeline; an upsell has no lead, so on the client's. */
    private function subject(Sale $sale): Lead|Client|Sale
    {
        $lead = $sale->lead_id !== null ? Lead::query()->acrossBrands()->find($sale->lead_id) : null;

        return $lead ?? Client::query()->acrossBrands()->find($sale->client_id) ?? $sale;
    }
}
