<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Enums\SaleKind;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ExchangeRate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\ClientCare;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One client from an imported sheet. A client is somebody who bought, so
 * the row becomes a sale with no lead (its amount, what they bought, the
 * day; nothing is sent to anyone) and the client's totals are counted from
 * it as from any other sale. The same email or phone joins the client that
 * is already there, and the same amount on the same day is not added twice,
 * so a sheet imported again changes nothing. A care team (an Upsell
 * department) and the person in it are set only on a client without one.
 */
final class ImportClient
{
    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, amount?: float|null, service?: string|null, closed_at: CarbonInterface, department?: Department|null, owner?: User|null}  $row
     * @return bool true when the client was new
     *
     * @throws InvalidArgumentException when the team or the person does not fit
     */
    public function handle(Brand $brand, array $row, User $by): bool
    {
        $email = filled($row['email'] ?? null) ? mb_strtolower(trim((string) $row['email'])) : null;
        $phone = filled($row['phone'] ?? null) ? trim((string) $row['phone']) : null;
        $department = $row['department'] ?? null;
        $owner = $row['owner'] ?? null;
        $amount = round(max((float) ($row['amount'] ?? 0), 0.0), 2);
        $closedAt = $row['closed_at'];

        if ($department !== null && ((int) $department->brand_id !== (int) $brand->getKey() || ! $department->isUpsell())) {
            throw new InvalidArgumentException($department->name.' is not an Upsell team of this brand.');
        }

        if ($owner !== null && $department !== null && ! $department->hasMember($owner)) {
            throw new InvalidArgumentException($owner->name.' is not in '.$department->name.'.');
        }

        return DB::transaction(function () use ($brand, $row, $by, $email, $phone, $department, $owner, $amount, $closedAt): bool {
            $client = Client::match($brand, $email, $phone);
            $created = $client === null;

            if ($client === null) {
                $client = Client::query()->create(['brand_id' => $brand->getKey(), 'name' => trim($row['name']), 'email' => $email, 'phone' => $phone]);
            } else {
                $client->forceFill(['email' => $client->email ?? $email, 'phone' => $client->phone ?? $phone])->save();
            }

            $again = Sale::query()->forBrand($brand)
                ->where('client_id', $client->getKey())
                ->whereNull('lead_id')
                ->where('amount', $amount)
                ->whereDate('closed_at', $closedAt->toDateString())
                ->exists();

            if (! $again) {
                $sale = Sale::query()->create([
                    'brand_id' => $brand->getKey(),
                    'department_id' => $department?->getKey() ?? $client->department_id,
                    'lead_id' => null,
                    'client_id' => $client->getKey(),
                    // The first purchase on the sheet is the front sale; one more for a client already here an upsell.
                    ...(ClientCare::ready() ? ['kind' => ($created ? SaleKind::Front : SaleKind::Upsell)->value] : []),
                    'user_id' => $owner?->getKey(),
                    'amount' => $amount,
                    'currency' => $brand->currency,
                    'amount_reporting' => ExchangeRate::convert($amount, $brand->currency, $closedAt->format('Y-m')),
                    'service' => filled($row['service'] ?? null) ? mb_substr(trim((string) $row['service']), 0, 190) : null,
                    'closed_at' => $closedAt,
                    'recorded_by' => $by->getKey(),
                ]);

                app(RecordPayment::class)->opening($sale, null, $closedAt, $by);
            }

            $client->recalculate();

            if (ClientCare::ready() && $department !== null && ! $client->isHandedOver()) {
                $client->forceFill([
                    'department_id' => $department->getKey(),
                    'owner_id' => $owner?->getKey(),
                    'handed_over_at' => now(),
                    'handed_over_by' => $by->getKey(),
                ])->save();
            }

            return $created;
        });
    }
}
