<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One internet address (or a range, "203.0.113.0/24") on Sign-in security:
 * the office network, from which people with the office rule may sign in,
 * or a blocked address, from which nobody may open LiquifyCRM.
 *
 * @property int $id
 * @property string $kind
 * @property string $address
 * @property string|null $label
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $creator
 */
class SignInAddress extends Model
{
    public const OFFICE = 'office';

    public const BLOCKED = 'blocked';

    protected $fillable = ['kind', 'address', 'label', 'created_by'];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
