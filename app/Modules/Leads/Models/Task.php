<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Leads\Support\LeadArchive;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up or reminder on a lead.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $lead_id
 * @property int|null $user_id
 * @property int|null $created_by
 * @property string $title
 * @property CarbonImmutable $due_at
 * @property string|null $timezone the time zone it was set in, when not the setter's own (FollowUpZones)
 * @property CarbonImmutable|null $done_at
 */
class Task extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'lead_id', 'user_id', 'created_by', 'title', 'due_at', 'timezone', 'done_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime'];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    /** @param  Builder<static>  $query */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->where('due_at', '<', ViewerTime::startOfToday());
    }

    /** @param  Builder<static>  $query */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->open()->whereBetween('due_at', [ViewerTime::startOfToday(), ViewerTime::endOfToday()]);
    }

    /**
     * Tasks on leads the user works, plus tasks assigned to them: a
     * colleague's follow-ups are not on their list, nor those of a lead
     * somebody archived.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->whereIn('lead_id', Lead::query()->workedBy($user)->select('leads.id'))
                ->orWhere('user_id', $user->getKey());
        })->when(LeadArchive::ready(), fn (Builder $query): Builder => $query->where(fn (Builder $live): Builder => $live
            ->whereNull('lead_id')
            ->orWhereNotIn('lead_id', Lead::query()->acrossBrands()->archived()->select('leads.id'))));
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /**
     * "5:00 PM London time" for a follow-up set in another time zone than the
     * viewer's (the client said UK time); null when there is nothing to add.
     */
    public function theirTime(?User $viewer = null): ?string
    {
        $zone = $this->getAttributes()['timezone'] ?? null;

        if (! is_string($zone) || ! ViewerTime::valid($zone)) {
            return null;
        }

        $there = $this->due_at->setTimezone($zone);

        if ($there->getOffset() === ViewerTime::local($this->due_at, $viewer)->getOffset()) {
            return null;
        }

        return $there->format('g:i A').' '.ViewerTime::city($zone).' time'.($there->isSameDay(ViewerTime::local($this->due_at, $viewer)) ? '' : ', '.$there->format('D j M'));
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_at->lt(ViewerTime::startOfToday());
    }
}
