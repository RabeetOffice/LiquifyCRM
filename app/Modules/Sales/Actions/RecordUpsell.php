<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Enums\SaleKind;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ExchangeRate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\ExchangeRates;
use App\Modules\Sales\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Another sale to someone who is already a client: a website after the
 * book, hosting after the website. It has no lead; it belongs to the client
 * and to the person who sold it, so it counts towards that person's, their
 * department's and the brand's targets and adds to the client's lifetime
 * value. A client may be upsold any number of times, until they are closed.
 */
final class RecordUpsell
{
    /**
     * @param  float|null  $paidNow  what the client paid with the upsell; null for all of it
     * @param  string|null  $currency  the currency the client was charged in, when not the brand's: the amounts are in it
     *
     * @throws InvalidArgumentException when the client is closed or the charged currency's rate cannot be had
     */
    public function handle(Client $client, float $amount, ?string $service, CarbonInterface $closedAt, User $by, ?User $seller = null, ?float $paidNow = null, ?string $currency = null): Sale
    {
        if ($client->isClosed()) {
            throw new InvalidArgumentException('This client is closed. Reopen them to record another sale.');
        }

        $brand = Brand::query()->findOrFail($client->brand_id);
        $seller ??= $by;

        // An upsell in another currency counts in the brand's, at that day's rate.
        $deal = app(ExchangeRates::class)->deal($brand->currency, $currency, $amount, $paidNow, $closedAt);
        $amount = $deal['amount'];
        $paidNow = $deal['paidNow'];

        $sale = DB::transaction(function () use ($client, $brand, $amount, $service, $closedAt, $by, $seller, $paidNow, $deal): Sale {
            $sale = Sale::query()->create([
                'brand_id' => $brand->getKey(),
                // The upsell counts for the department that looks after the client.
                'department_id' => $client->department_id ?? ($seller->departmentIds($brand)[0] ?? null),
                'lead_id' => null,
                'client_id' => $client->getKey(),
                'kind' => SaleKind::Upsell->value,
                'user_id' => $seller->getKey(),
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

            app(RecordPayment::class)->opening($sale, $paidNow, $closedAt, $by, $deal['chargedPaid']);

            $client->recalculate();

            // Recorded in the name of a teammate who is not on the client yet: they sold to it, so they work on it
            // from now on (and the "Open client" they are sent opens).
            if ($client->department_id !== null && $client->owner_id !== $seller->getKey() && ! $client->helpers()->where('users.id', $seller->getKey())->exists()) {
                $client->helpers()->attach($seller->getKey(), ['added_by' => $by->getKey()]);
            }

            Audit::log(
                'sales.upsell_recorded',
                $client,
                ['sale_id' => $sale->getKey(), 'amount' => $amount, 'currency' => $brand->currency, ...$deal['columns'], 'service' => $sale->service, 'closed_at' => $closedAt->toDateString(), 'sold_by' => $seller->getKey()],
                'Upsell recorded: '.$sale->label().($sale->service ? ' · '.$sale->service : '').' · '.$seller->name.($sale->due() > 0.0 ? ' · '.Money::format($sale->dealDue(), $sale->dealCurrency()).' to collect' : ''),
                brand: $brand,
                department: $sale->department_id,
                causer: $by,
                logName: 'sales',
            );

            return $sale;
        });

        DashboardCache::flush((int) $brand->getKey());
        $this->tellManagers($sale, $client, $brand, $seller, $by);

        return $sale;
    }

    private function tellManagers(Sale $sale, Client $client, Brand $brand, User $seller, User $by): void
    {
        $department = $sale->department_id !== null ? Department::query()->forBrand($brand)->find($sale->department_id) : null;
        $people = $department?->managers()->get() ?? collect();

        // Recorded for somebody else: that person hears about it too.
        if (! $seller->is($by)) {
            $people = $people->push($seller);
        }

        $people = $people->unique('id')->reject(fn (User $person): bool => $person->is($by))->values();

        if ($people->isEmpty()) {
            return;
        }

        Notification::send($people, new AppNotification(
            'Upsell recorded: '.$sale->label(),
            $seller->name.' sold '.($sale->service ?? 'more').' to '.$client->name.'.',
            'success',
            $brand->url('clients/'.$client->getKey()),
            icon: 'heroicon-o-arrow-trending-up',
            actionLabel: 'Open client',
        ));
    }
}
