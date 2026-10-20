<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Sales\Enums\SaleKind;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ExchangeRate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\Sales\Support\ExchangeRates;
use App\Modules\Sales\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * "Mark as Won": record the sale in the brand's currency, turn the lead's
 * person into a client (or add to the client they already are), move the
 * lead to Won. The sale counts towards the Agent's, the department's and the
 * brand's targets at the same moment, because all three read the same row.
 */
final class RecordSale
{
    /**
     * @param  float|null  $paidNow  what the client paid with the sale; null for all of it
     * @param  string|null  $currency  the currency the client was charged in, when not the brand's: the amounts are in it
     *
     * @throws \InvalidArgumentException when the charged currency's rate cannot be had
     */
    public function handle(Lead $lead, float $amount, ?string $service, CarbonInterface $closedAt, User $by, ?float $paidNow = null, ?string $currency = null): Sale
    {
        $brand = Brand::query()->findOrFail($lead->brand_id);

        // A sale in another currency counts in the brand's, at that day's rate.
        $deal = app(ExchangeRates::class)->deal($brand->currency, $currency, $amount, $paidNow, $closedAt);
        $amount = $deal['amount'];
        $paidNow = $deal['paidNow'];

        $sale = DB::transaction(function () use ($lead, $brand, $amount, $service, $closedAt, $by, $paidNow, $deal): Sale {
            $client = ($lead->client_id !== null ? Client::query()->forBrand($brand)->find($lead->client_id) : null)
                ?? Client::match($brand, $lead->email, $lead->phone)
                ?? Client::query()->create(['brand_id' => $brand->getKey(), 'name' => $lead->name, 'email' => $lead->email, 'phone' => $lead->phone]);

            $sale = Sale::query()->create([
                'brand_id' => $brand->getKey(),
                'department_id' => $lead->department_id,
                'lead_id' => $lead->getKey(),
                'client_id' => $client->getKey(),
                // A front sale: it won a lead. What the client buys later is an upsell (RecordUpsell).
                ...(ClientCare::ready() ? ['kind' => SaleKind::Front->value] : []),
                // The sale belongs to whoever owns the lead; an unowned lead to the person recording it.
                'user_id' => $lead->assigned_to ?? $by->getKey(),
                'amount' => $amount,
                'currency' => $brand->currency,
                'amount_reporting' => ExchangeRate::convert($amount, $brand->currency, $closedAt->format('Y-m')),
                'service' => filled($service) ? trim((string) $service) : null,
                'closed_at' => $closedAt,
                'recorded_by' => $by->getKey(),
            ]);

            if ($deal['columns'] !== []) {
                $sale->forceFill($deal['columns'])->save();
            }

            // The whole deal counts; the payments say how much of it is in.
            app(RecordPayment::class)->opening($sale, $paidNow, $closedAt, $by, $deal['chargedPaid']);

            $client->recalculate();

            $lead->forceFill([
                'client_id' => $client->getKey(),
                'value' => Sale::query()->forBrand($brand)->where('lead_id', $lead->getKey())->sum('amount'),
                'currency' => $brand->currency,
            ])->save();

            $won = LeadStatus::wonFor($brand);
            $lead->loadMissing('status');

            if ($won !== null && ! $lead->status->is_won) {
                app(ChangeLeadStatus::class)->handle($lead, $won, $by);
            }

            Audit::log(
                'sales.recorded',
                $lead,
                ['sale_id' => $sale->getKey(), 'amount' => $amount, 'currency' => $brand->currency, ...$deal['columns'], 'service' => $sale->service, 'closed_at' => $closedAt->toDateString(), 'client_id' => $client->getKey()],
                'Sale recorded: '.$sale->label().($sale->service ? ' · '.$sale->service : '').($sale->due() > 0.0 ? ' · '.Money::format($sale->dealPaid(), $sale->dealCurrency()).' paid, '.Money::format($sale->dealDue(), $sale->dealCurrency()).' to collect' : ''),
                brand: $brand,
                causer: $by,
                logName: 'sales',
            );

            return $sale;
        });

        $this->tellManagers($sale, $lead, $brand, $by);

        return $sale;
    }

    private function tellManagers(Sale $sale, Lead $lead, Brand $brand, User $by): void
    {
        $department = $sale->department_id !== null ? Department::query()->forBrand($brand)->find($sale->department_id) : null;
        $managers = $department?->managers()->get()->reject(fn (User $manager): bool => $manager->is($by));

        if ($managers === null || $managers->isEmpty()) {
            return;
        }

        Notification::send($managers, new AppNotification(
            'Sale recorded: '.$sale->label(),
            $by->name.' closed '.$lead->name.($sale->service ? ' ('.$sale->service.')' : '').'.',
            'success',
            $brand->url('leads/'.$lead->getKey()),
            icon: 'heroicon-o-trophy',
            actionLabel: 'Open lead',
        ));
    }
}
