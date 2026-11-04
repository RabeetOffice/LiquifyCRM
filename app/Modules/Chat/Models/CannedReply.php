<?php

declare(strict_types=1);

namespace App\Modules\Chat\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply the team types often, called up with its shortcut in the message box (`/price`). The company's
 * (Admins write them), a brand's (its Managers) and a person's own. The words may carry {visitor_name},
 * {agent_name} and {brand_name}, filled in when the reply is put in the box.
 *
 * @property int $id
 * @property string $scope
 * @property int|null $brand_id
 * @property int|null $user_id
 * @property string $shortcut
 * @property string $title
 * @property string $body
 * @property CarbonImmutable|null $updated_at
 */
class CannedReply extends Model
{
    public const GLOBAL = 'global';

    public const BRAND = 'brand';

    public const PERSONAL = 'personal';

    public const SCOPES = [
        self::GLOBAL => 'Everybody, every brand',
        self::BRAND => 'This brand',
        self::PERSONAL => 'Only me',
    ];

    public const VARIABLES = ['{visitor_name}' => 'the visitor\'s name', '{agent_name}' => 'your first name', '{brand_name}' => 'the brand\'s name'];

    protected $table = 'canned_replies';

    protected $fillable = ['scope', 'brand_id', 'user_id', 'shortcut', 'title', 'body'];

    /**
     * What this person may use in this brand: the company's, the brand's and their own.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUsableBy(Builder $query, User $user, int $brandId): Builder
    {
        return $query->where(fn (Builder $usable): Builder => $usable
            ->where('scope', self::GLOBAL)
            ->orWhere(fn (Builder $brand): Builder => $brand->where('scope', self::BRAND)->where('brand_id', $brandId))
            ->orWhere(fn (Builder $own): Builder => $own->where('scope', self::PERSONAL)->where('user_id', $user->getKey())));
    }

    /** The words with the names filled in. A visitor who gave no name is "there": "Hi there". */
    public function filled(?string $visitorName, string $agentName, string $brandName): string
    {
        $first = fn (?string $name): string => trim(explode(' ', trim((string) $name))[0]);

        return strtr($this->body, [
            '{visitor_name}' => $first($visitorName) !== '' && ! str_starts_with((string) $visitorName, 'Visitor ') ? $first($visitorName) : 'there',
            '{agent_name}' => $first($agentName) !== '' ? $first($agentName) : 'the team',
            '{brand_name}' => $brandName,
        ]);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
