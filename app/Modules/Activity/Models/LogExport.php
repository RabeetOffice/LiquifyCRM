<?php

declare(strict_types=1);

namespace App\Modules\Activity\Models;

use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requested download of the last 7, 15 or 30 days of logs.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $brand_id
 * @property list<int>|null $department_ids
 * @property int $range_days
 * @property string $status
 * @property string|null $path
 * @property int|null $rows
 * @property string|null $error
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 */
class LogExport extends Model
{
    public const RANGES = [7, 15, 30];

    protected $fillable = ['user_id', 'brand_id', 'department_ids', 'range_days', 'status', 'path', 'rows', 'error', 'expires_at'];

    protected $attributes = ['status' => 'pending', 'brand_id' => null, 'department_ids' => null, 'path' => null, 'rows' => null, 'error' => null, 'expires_at' => null];

    protected function casts(): array
    {
        return ['department_ids' => 'array', 'range_days' => 'integer', 'rows' => 'integer', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->path !== null && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
