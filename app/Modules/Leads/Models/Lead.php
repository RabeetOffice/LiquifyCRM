<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Database\Factories\LeadFactory;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Support\ContactMask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * One person or enquiry inside a brand, with status, owner, notes,
 * follow-ups and an activity timeline.
 *
 * @property int $id
 * @property int $brand_id
 * @property LeadSource $source_type
 * @property int|null $source_id
 * @property string|null $external_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $message
 * @property int $status_id
 * @property int|null $department_id
 * @property int|null $assigned_to
 * @property CarbonImmutable|null $assigned_at
 * @property int|null $client_id
 * @property CarbonImmutable|null $imported_at
 * @property string|null $value
 * @property string|null $currency
 * @property array<string, mixed>|null $utm
 * @property array<string, mixed>|null $fields
 * @property CarbonImmutable|null $next_follow_up_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $first_contacted_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read LeadStatus $status
 */
class Lead extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'brand_id', 'source_type', 'source_id', 'external_id', 'name', 'email', 'phone', 'message',
        'status_id', 'department_id', 'assigned_to', 'assigned_at', 'client_id', 'imported_at',
        'value', 'currency', 'utm', 'fields', 'next_follow_up_at', 'last_activity_at',
        'first_contacted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => LeadSource::class,
            'utm' => 'array',
            'fields' => 'array',
            'value' => 'decimal:2',
            'assigned_at' => 'datetime',
            'imported_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'first_contacted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $lead): void {
            $lead->email = filled($lead->email) ? mb_strtolower(trim((string) $lead->email)) : null;
            $lead->last_activity_at ??= now();

            $brandId = $lead->getAttribute('brand_id');

            if ($lead->getAttribute('status_id') === null && $brandId !== null) {
                $lead->status_id = LeadStatus::defaultFor((int) $brandId)?->getKey();
            }
        });
    }

    // --------------------------------------------------------------- relations

    /** @return BelongsTo<LeadStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<LeadNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest('id');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('due_at');
    }

    /** @return MorphMany<Activity, $this> */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest('id');
    }

    // ------------------------------------------------------------------ scopes

    /**
     * Layer 2 of brand isolation: the leads a person works (see workedBy),
     * and for front sales also the rest of their team's leads and the leads
     * no team has yet, so whoever is free sees a new lead at once and takes
     * it (user request, 19 Oct 2026). Seeing a colleague's lead is all it
     * gives: changing one takes owning it (LeadPolicy::update).
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
        $front = $brand !== null ? $user->frontDepartmentIds($brand) : [];

        if ($front === []) {
            return $query->workedBy($user);
        }

        return $query->where(fn (Builder $query): Builder => $query
            ->where(fn (Builder $own): Builder => $own->workedBy($user))
            ->orWhereIn('department_id', $front)
            ->orWhereNull('department_id'));
    }

    /**
     * The leads a person works: Admins every lead of the brand, a Manager
     * the leads of the departments they lead, an Agent only the leads
     * assigned to them. Their own numbers and follow-ups come from here.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWorkedBy(Builder $query, User $user): Builder
    {
        if ($user->isGlobalAdmin()) {
            return $query;
        }

        $brand = app(CurrentBrand::class)->get();

        if ($brand === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isManagerIn($brand)) {
            $departmentIds = $user->managedDepartmentIds($brand);

            return $query->where(function (Builder $query) use ($departmentIds, $user): void {
                $query->whereIn('department_id', $departmentIds)
                    ->orWhere('assigned_to', $user->getKey());
            });
        }

        if ($user->isAgentIn($brand)) {
            return $query->where('assigned_to', $user->getKey());
        }

        return $query->whereRaw('1 = 0');
    }

    /** @param  Builder<static>  $query */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to');
    }

    /** The New / Unassigned queue: nobody owns the lead yet, oldest first. */
    public function scopeQueue(Builder $query): Builder
    {
        return $query->whereNull('assigned_to')->oldest('id');
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $status): Builder => $status->where('is_won', false)->where('is_lost', false));
    }

    /**
     * The bin: leads somebody deleted. A lead merged into another is deleted
     * too, but has no "deleted" line (its history moved to the lead that
     * stayed), so it is not in the bin: restoring it would make an empty twin.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInBin(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull($this->getQualifiedDeletedAtColumn())
            ->whereHas('activities', fn (Builder $activity): Builder => $activity->where('event', 'leads.deleted'));
    }

    /** @param  Builder<static>  $query */
    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        return $query->where('assigned_to', $user instanceof User ? $user->getKey() : $user);
    }

    // ----------------------------------------------------------------- helpers

    public function isWon(): bool
    {
        return (bool) $this->status->is_won;
    }

    public function isLost(): bool
    {
        return (bool) $this->status->is_lost;
    }

    public function isOpen(): bool
    {
        return ! $this->isWon() && ! $this->isLost();
    }

    public function isAssigned(): bool
    {
        return $this->assigned_to !== null;
    }

    public function maskedEmail(): ?string
    {
        return ContactMask::email($this->email);
    }

    public function maskedPhone(): ?string
    {
        return ContactMask::phone($this->phone);
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->saveQuietly();
    }
}
