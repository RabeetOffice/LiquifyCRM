<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who has bought at least once from a brand. Created on the first
 * sale; a later purchase by the same email or phone joins the same client,
 * so lifetime value stays correct.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property CarbonImmutable|null $first_sale_at
 * @property CarbonImmutable|null $last_sale_at
 * @property string $lifetime_value
 * @property int $sales_count
 */
class Client extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'name', 'email', 'phone', 'first_sale_at', 'last_sale_at', 'lifetime_value', 'sales_count'];

    protected $attributes = ['email' => null, 'phone' => null, 'first_sale_at' => null, 'last_sale_at' => null, 'lifetime_value' => 0, 'sales_count' => 0];

    protected function casts(): array
    {
        return [
            'first_sale_at' => 'datetime',
            'last_sale_at' => 'datetime',
            'lifetime_value' => 'decimal:2',
            'sales_count' => 'integer',
        ];
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class)->latest('closed_at');
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class)->latest('id');
    }

    /** The client of a brand with this email or phone, if there is one. */
    public static function match(Brand|int $brand, ?string $email, ?string $phone): ?self
    {
        $email = filled($email) ? mb_strtolower(trim((string) $email)) : null;
        $phone = filled($phone) ? trim((string) $phone) : null;

        if ($email === null && $phone === null) {
            return null;
        }

        return self::query()
            ->forBrand($brand)
            ->where(function (Builder $query) use ($email, $phone): void {
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }

                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->oldest('id')
            ->first();
    }

    /** Recount from the sales, so edits and removals can never drift. */
    public function recalculate(): void
    {
        $totals = Sale::query()->acrossBrands()->where('client_id', $this->getKey())
            ->selectRaw('COUNT(*) as sales, COALESCE(SUM(amount), 0) as value, MIN(closed_at) as first_at, MAX(closed_at) as last_at')
            ->toBase()
            ->first();

        $this->forceFill([
            'sales_count' => (int) ($totals->sales ?? 0),
            'lifetime_value' => (string) ($totals->value ?? 0),
            'first_sale_at' => $totals->first_at ?? null,
            'last_sale_at' => $totals->last_at ?? null,
        ])->save();
    }
}
