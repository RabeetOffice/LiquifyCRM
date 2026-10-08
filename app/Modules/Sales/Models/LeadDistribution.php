<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the distribution history: who moved how many leads to which
 * department or person, and how.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $from_user_id
 * @property string $to_type
 * @property int $to_id
 * @property int $lead_count
 * @property string $method
 * @property array<string, mixed>|null $filters
 * @property CarbonImmutable|null $created_at
 */
class LeadDistribution extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'from_user_id', 'to_type', 'to_id', 'lead_count', 'method', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'lead_count' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }
}
