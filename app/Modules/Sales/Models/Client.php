<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Enums\ClientStage;
use App\Modules\Sales\Enums\SaleKind;
use App\Modules\Sales\Support\ClientCare;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A person who has bought at least once from a brand. Created on the first
 * sale; a later purchase by the same email or phone joins the same client,
 * so lifetime value stays correct. After that first sale the client is
 * handed to an Upsell department, where one person looks after them (others
 * may help), sells them more and keeps their files, until the client is
 * closed.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property int|null $department_id
 * @property int|null $owner_id
 * @property CarbonImmutable|null $handed_over_at
 * @property int|null $handed_over_by
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property string|null $closed_note
 * @property CarbonImmutable|null $first_sale_at
 * @property CarbonImmutable|null $last_sale_at
 * @property string $lifetime_value
 * @property int $sales_count
 * @property-read Department|null $department
 * @property-read User|null $owner
 */
class Client extends Model
{
    use BelongsToBrand;

    protected $fillable = [
        'brand_id', 'name', 'email', 'phone', 'department_id', 'owner_id', 'handed_over_at', 'handed_over_by',
        'closed_at', 'closed_by', 'closed_note', 'first_sale_at', 'last_sale_at', 'lifetime_value', 'sales_count',
    ];

    protected $attributes = ['email' => null, 'phone' => null, 'first_sale_at' => null, 'last_sale_at' => null, 'lifetime_value' => 0, 'sales_count' => 0];

    protected function casts(): array
    {
        return [
            'handed_over_at' => 'datetime',
            'closed_at' => 'datetime',
            'first_sale_at' => 'datetime',
            'last_sale_at' => 'datetime',
            'lifetime_value' => 'decimal:2',
            'sales_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A client made a moment ago knows only what it was given; read the row back
        // so its department, owner and dates are there (empty) like on any other client.
        static::created(fn (self $client) => $client->refresh());
    }

    // --------------------------------------------------------------- relations

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class)->latest('closed_at');
    }

    /** @return HasMany<Sale, $this> */
    public function upsells(): HasMany
    {
        return $this->hasMany(Sale::class)->where('kind', SaleKind::Upsell->value);
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class)->latest('id');
    }

    /**
     * The Upsell department that looks after the client.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The person who looks after the client.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function handedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Others who work on the client beside the owner.
     *
     * @return BelongsToMany<User, $this>
     */
    public function helpers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_user')->withPivot('added_by')->withTimestamps();
    }

    /**
     * Files added on the client's own page. The whole storage, with what
     * front sales added from the lead, is ClientFile::ofClient().
     *
     * @return MorphMany<ClientFile, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(ClientFile::class, 'fileable')->latest('id');
    }

    /** @return HasMany<ClientNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class)->latest('id');
    }

    // ------------------------------------------------------------------ scopes

    /**
     * Clients a person looks after: Admins every client, a Manager those of
     * the departments they lead, anyone those they own or help with.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInCareOf(Builder $query, User $user): Builder
    {
        if ($user->isGlobalAdmin()) {
            return $query;
        }

        $brand = app(CurrentBrand::class)->get();

        if ($brand === null || ! ClientCare::ready()) {
            return $query->whereRaw('1 = 0');
        }

        $managed = $user->isManagerIn($brand) ? $user->managedDepartmentIds($brand) : [];

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where($this->qualifyColumn('owner_id'), $user->getKey())
            ->orWhereIn($this->qualifyColumn('department_id'), $managed)
            ->orWhereHas('helpers', fn (Builder $helpers): Builder => $helpers->where('users.id', $user->getKey())));
    }

    /**
     * Clients a person may open: those they look after, and for a Manager
     * the clients their departments sold to that nobody has taken over yet.
     * Once a client is handed to an Upsell department, front sales no
     * longer sees them.
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

        $soldByTheirTeam = Sale::query()->visibleTo($user)->select('client_id');

        if (! ClientCare::ready()) {
            return $user->isManagerIn($brand) ? $query->whereIn($this->qualifyColumn('id'), $soldByTheirTeam) : $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $inner) use ($user, $brand, $soldByTheirTeam): void {
            $inner->where(fn (Builder $care): Builder => $care->inCareOf($user));

            if ($user->isManagerIn($brand)) {
                $inner->orWhere(fn (Builder $front): Builder => $front
                    ->whereNull($this->qualifyColumn('department_id'))
                    ->whereIn($this->qualifyColumn('id'), $soldByTheirTeam));
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInStage(Builder $query, ClientStage $stage): Builder
    {
        return match ($stage) {
            ClientStage::Closed => $query->whereNotNull($this->qualifyColumn('closed_at')),
            ClientStage::Front => $query->whereNull($this->qualifyColumn('closed_at'))->whereNull($this->qualifyColumn('department_id')),
            ClientStage::Waiting => $query->whereNull($this->qualifyColumn('closed_at'))->whereNotNull($this->qualifyColumn('department_id'))->whereNull($this->qualifyColumn('owner_id')),
            ClientStage::Active => $query->whereNull($this->qualifyColumn('closed_at'))->whereNotNull($this->qualifyColumn('owner_id')),
        };
    }

    // ----------------------------------------------------------------- helpers

    public function stage(): ClientStage
    {
        $attributes = $this->getAttributes();

        return match (true) {
            ($attributes['closed_at'] ?? null) !== null => ClientStage::Closed,
            ($attributes['owner_id'] ?? null) !== null => ClientStage::Active,
            ($attributes['department_id'] ?? null) !== null => ClientStage::Waiting,
            default => ClientStage::Front,
        };
    }

    public function isClosed(): bool
    {
        return $this->stage() === ClientStage::Closed;
    }

    public function isHandedOver(): bool
    {
        return ($this->getAttributes()['department_id'] ?? null) !== null || ($this->getAttributes()['owner_id'] ?? null) !== null;
    }

    /** The owner, a helper, a Manager of the client's department, or an Admin. */
    public function isInCareOf(User $user): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        if (! ClientCare::ready() || ! $user->isMemberOf($this->brand_id)) {
            return false;
        }

        // Raw attributes: a client made a moment ago has no owner or department key yet.
        $ownerId = $this->getAttributes()['owner_id'] ?? null;
        $departmentId = $this->getAttributes()['department_id'] ?? null;

        if ($ownerId !== null && (int) $ownerId === (int) $user->getKey()) {
            return true;
        }

        if ($departmentId !== null && $user->isManagerIn($this->brand_id) && $user->managesDepartment((int) $departmentId)) {
            return true;
        }

        return $this->helpers()->where('users.id', $user->getKey())->exists();
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
