<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Concerns\BelongsToBrand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Enums\LeadSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the leads of one source go by themselves: a website or a Facebook
 * Page sends them to a department, or straight to one person in it.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $channel
 * @property int $source_id
 * @property int|null $department_id
 * @property int|null $user_id
 * @property int|null $set_by
 * @property-read Department|null $department
 * @property-read User|null $user
 */
class LeadRoute extends Model
{
    use BelongsToBrand;

    public const WEBSITE = 'website';

    /** A Page's lead forms and its Messenger chats share one rule. */
    public const FACEBOOK = 'facebook';

    protected $fillable = ['brand_id', 'channel', 'source_id', 'department_id', 'user_id', 'set_by'];

    protected $attributes = ['department_id' => null, 'user_id' => null, 'set_by' => null];

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The rule a lead source falls under, if that kind of source can have one. */
    public static function channelOf(LeadSource $source): ?string
    {
        return match ($source) {
            LeadSource::Website => self::WEBSITE,
            LeadSource::Facebook, LeadSource::Messenger => self::FACEBOOK,
            default => null,
        };
    }
}
