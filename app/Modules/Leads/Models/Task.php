<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
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
 * @property CarbonImmutable|null $done_at
 */
class Task extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'lead_id', 'user_id', 'created_by', 'title', 'due_at', 'done_at'];

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
        return $query->open()->where('due_at', '<', now()->startOfDay());
    }

    /** @param  Builder<static>  $query */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->open()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    /** Tasks on leads the user may see, plus tasks assigned to them. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->whereIn('lead_id', Lead::query()->visibleTo($user)->select('leads.id'))
                ->orWhere('user_id', $user->getKey());
        });
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_at->lt(now()->startOfDay());
    }
}
