<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Enums\SaleKind;
use App\Modules\Sales\Support\ExchangeRates;
use App\Modules\Sales\Support\Money;
use App\Modules\Sales\Support\SalePayments;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One recorded sale with an amount in the brand's currency: the front sale
 * that won a lead, or an upsell to a client afterwards (no lead). It counts
 * towards the seller's, the department's and the brand's targets.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $department_id
 * @property int|null $lead_id
 * @property int $client_id
 * @property string|null $kind
 * @property int|null $user_id
 * @property string $amount
 * @property string|null $amount_paid
 * @property string $currency
 * @property string|null $amount_reporting
 * @property string|null $charged_currency
 * @property string|null $charged_amount
 * @property string|null $charged_paid
 * @property string|null $exchange_rate
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
        'brand_id', 'department_id', 'lead_id', 'client_id', 'kind', 'user_id', 'amount', 'currency',
        'amount_reporting', 'service', 'closed_at', 'recorded_by',
    ];

    protected $attributes = ['department_id' => null, 'lead_id' => null, 'user_id' => null, 'amount_reporting' => null, 'service' => null, 'recorded_by' => null];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_reporting' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'charged_amount' => 'decimal:2',
            'charged_paid' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
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

    /** Front sale or upsell. A sale from before the column existed is a front sale. */
    public function kind(): SaleKind
    {
        return SaleKind::tryFrom((string) ($this->getAttributes()['kind'] ?? '')) ?? SaleKind::Front;
    }

    public function isUpsell(): bool
    {
        return $this->kind() === SaleKind::Upsell;
    }

    /** @return HasMany<SalePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /** What is in so far. A sale from before part payments counts as paid in full. */
    public function paid(): float
    {
        return SalePayments::ready() && $this->amount_paid !== null ? (float) $this->amount_paid : (float) $this->amount;
    }

    /** What the client still owes on this deal. */
    public function due(): float
    {
        return max(0.0, round((float) $this->amount - $this->paid(), 2));
    }

    public function isPaidInFull(): bool
    {
        return $this->due() <= 0.0;
    }

    /**
     * Sold in another currency than the brand's (20 Oct 2026): `amount` is the
     * brand's currency at the day's rate; the charged_* columns are what the
     * client really pays.
     */
    public function isForeign(): bool
    {
        return ExchangeRates::ready() && filled($this->charged_currency) && $this->charged_amount !== null;
    }

    /** The currency the client was charged in. */
    public function dealCurrency(): string
    {
        return $this->isForeign() ? (string) $this->charged_currency : $this->currency;
    }

    /** The deal in the currency the client was charged in. */
    public function dealAmount(): float
    {
        return $this->isForeign() ? (float) $this->charged_amount : (float) $this->amount;
    }

    /** What is in so far, in the currency the client was charged in. */
    public function dealPaid(): float
    {
        if (! $this->isForeign()) {
            return $this->paid();
        }

        return SalePayments::ready() && $this->charged_paid !== null ? (float) $this->charged_paid : (float) $this->charged_amount;
    }

    /** What the client still owes, in the currency they were charged in. */
    public function dealDue(): float
    {
        return max(0.0, round($this->dealAmount() - $this->dealPaid(), 2));
    }

    /** "£1,000.00", or "£1,000.00 (€1,187.60)" for a sale in another currency than the brand's. */
    public function label(): string
    {
        return $this->isForeign()
            ? Money::format($this->charged_amount, (string) $this->charged_currency).' ('.Money::format($this->amount, $this->currency).')'
            : Money::format($this->amount, $this->currency);
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
