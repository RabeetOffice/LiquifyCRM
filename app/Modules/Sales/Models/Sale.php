<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded sale: a won lead with an amount in the brand's currency. It
 * counts towards the Agent's, the department's and the brand's targets.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $department_id
 * @property int|null $lead_id
 * @property int $client_id
 * @property int|null $user_id
 * @property string $amount
 * @property string $currency
 * @property string|null $amount_reporting
 * @property string|null $service
 * @property CarbonImmutable $closed_at
 * @property int|null $recorded_by
 * @property-read Client $client
 * @property-read User|null $closer
 */
class Sale extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'department_id', 'lead_id', 'client_id', 'user_id', 'amount', 'currency',
        'amount_reporting', 'service', 'closed_at', 'recorded_by',
    ];

    protected $attributes = ['department_id' => null, 'lead_id' => null, 'user_id' => null, 'amount_reporting' => null, 'service' => null, 'recorded_by' => null];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_reporting' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** The person who closed the sale. @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * "See sales and performance": Admins all, a Manager their departments,
     * an Agent their own.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isGlobalAdmin()) {
            return $query;
        }

        $brand = app(CurrentBrand::class)->get();

        if ($brand === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isManagerIn($brand)) {
            return $query->where(fn (Builder $inner): Builder => $inner
                ->whereIn('department_id', $user->managedDepartmentIds($brand))
                ->orWhere('user_id', $user->getKey()));
        }

        return $query->where('user_id', $user->getKey());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInPeriod(Builder $query, string $period): Builder
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period) ?: now()->startOfMonth();

        return $query->where('closed_at', '>=', $start)->where('closed_at', '<', $start->addMonth());
    }
}
