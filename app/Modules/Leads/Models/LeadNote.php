<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note on a lead, shown on its timeline.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $lead_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $is_pinned
 */
class LeadNote extends Model
{
    use BelongsToBrand;

    protected $fillable = ['brand_id', 'lead_id', 'user_id', 'body', 'is_pinned'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
