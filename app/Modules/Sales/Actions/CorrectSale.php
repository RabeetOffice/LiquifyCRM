<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ExchangeRate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\ExchangeRates;
use App\Modules\Sales\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * An Admin corrects or removes a recorded sale. Everything that was counted
 * from it is counted again: the client's lifetime value, the lead's value,
 * and (through the sale row itself) the targets. Both are written to the
 * lead's timeline with the old and new values; an upsell has no lead, so
 * its correction is written on the client.
 */
final class CorrectSale
{
    /** @param  float  $amount  in the currency the client was charged in (the brand's, unless the sale was in another) */
    public function update(Sale $sale, float $amount, ?string $service, CarbonInterface $closedAt, User $by): Sale
    {
        return DB::transaction(function () use ($sale, $amount, $service, $closedAt, $by): Sale {
            $before = ['amount' => $sale->dealAmount(), 'service' => $sale->service, 'closed_at' => $sale->closed_at->toDateString()];
            $currency = $sale->dealCurrency();

            // A sale in another currency keeps its rate: the brand's amount follows the corrected one.
            if ($sale->isForeign()) {
                $sale->forceFill(['charged_amount' => round($amount, 2)]);
            }

            $inBrand = $sale->isForeign() ? ExchangeRates::convert($amount, (float) $sale->exchange_rate) : $amount;

            $sale->update([
                'amount' => $inBrand,
                'service' => filled($service) ? trim((string) $service) : null,
                'closed_at' => $closedAt,
                'amount_reporting' => ExchangeRate::convert($inBrand, $sale->currency, $closedAt->format('Y-m')),
            ]);

            $this->recount($sale);

            Audit::log(
                'sales.updated',
                $this->lead($sale) ?? $this->client($sale) ?? $sale,
                ['sale_id' => $sale->getKey(), 'before' => $before, 'after' => ['amount' => $amount, 'service' => $sale->service, 'closed_at' => $closedAt->toDateString()]],
                'Sale corrected: '.Money::format($before['amount'], $currency).' → '.Money::format($amount, $currency),
                brand: $sale->brand_id,
                causer: $by,
                logName: 'sales',
            );

            return $sale;
        });
    }

    public function delete(Sale $sale, User $by): void
    {
        DB::transaction(function () use ($sale, $by): void {
            $lead = $this->lead($sale);
            $sale->delete();
            $this->recount($sale);

            Audit::log(
                'sales.deleted',
                $lead ?? $this->client($sale) ?? $sale,
                ['sale_id' => $sale->getKey(), 'amount' => (float) $sale->amount, 'currency' => $sale->currency, 'service' => $sale->service, 'closed_at' => $sale->closed_at->toDateString(), 'closed_by' => $sale->user_id],
                'Sale removed: '.Money::format($sale->amount, $sale->currency),
                brand: $sale->brand_id,
                causer: $by,
                logName: 'sales',
            );

            // A lead is only Won while it has a sale. Without one it returns to
            // the last open step of the pipeline, where it was before it was won.
            if ($lead !== null && ! Sale::query()->forBrand($sale->brand_id)->where('lead_id', $lead->getKey())->exists()) {
                $lead->loadMissing('status');
                $open = LeadStatus::query()->forBrand($sale->brand_id)->where('is_won', false)->where('is_lost', false)->orderByDesc('sort')->orderByDesc('id')->first();

                if ($lead->status->is_won && $open !== null) {
                    app(ChangeLeadStatus::class)->handle($lead, $open, $by);
                }
            }
        });
    }

    private function recount(Sale $sale): void
    {
        $this->client($sale)?->recalculate();

        $lead = $this->lead($sale);

        if ($lead !== null) {
            $total = Sale::query()->forBrand($sale->brand_id)->where('lead_id', $lead->getKey())->sum('amount');
            $lead->forceFill(['value' => (float) $total > 0 ? $total : null])->save();
        }
    }

    private function client(Sale $sale): ?Client
    {
        return Client::query()->forBrand($sale->brand_id)->find($sale->client_id);
    }

    private function lead(Sale $sale): ?Lead
    {
        return $sale->lead_id !== null ? Lead::query()->forBrand($sale->brand_id)->find($sale->lead_id) : null;
    }
}
