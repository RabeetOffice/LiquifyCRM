<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Concerns\BelongsToBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-brand pipeline step. The document's six defaults are created for
 * every brand; `color` is the pill text and `tint` its soft background.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $name
 * @property string $slug
 * @property string $color
 * @property string $tint
 * @property int $sort
 * @property bool $is_default
 * @property bool $is_won
 * @property bool $is_lost
 */
class LeadStatus extends Model
{
    use BelongsToBrand;

    /** @var list<array{name: string, slug: string, color: string, tint: string, is_default?: bool, is_won?: bool, is_lost?: bool}> */
    public const DEFAULTS = [
        ['name' => 'New', 'slug' => 'new', 'color' => '#C2410C', 'tint' => '#FFF4ED', 'is_default' => true],
        ['name' => 'Contacted', 'slug' => 'contacted', 'color' => '#0369A1', 'tint' => '#F0F9FF'],
        ['name' => 'Qualified', 'slug' => 'qualified', 'color' => '#6D28D9', 'tint' => '#F5F3FF'],
        ['name' => 'Proposal sent', 'slug' => 'proposal-sent', 'color' => '#4338CA', 'tint' => '#EEF2FF'],
        ['name' => 'Won', 'slug' => 'won', 'color' => '#15803D', 'tint' => '#F0FDF4', 'is_won' => true],
        ['name' => 'Lost', 'slug' => 'lost', 'color' => '#57534E', 'tint' => '#F5F5F4', 'is_lost' => true],
    ];

    protected $fillable = ['brand_id', 'name', 'slug', 'color', 'tint', 'sort', 'is_default', 'is_won', 'is_lost'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_default' => 'boolean',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
        ];
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'status_id');
    }

    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    /**
     * Give a brand the document's pipeline if it has no statuses yet.
     */
    public static function createDefaultsFor(Brand|int $brand): void
    {
        $brandId = $brand instanceof Brand ? (int) $brand->getKey() : $brand;

        if (static::query()->forBrand($brandId)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $sort => $status) {
            static::query()->create([
                ...$status,
                'brand_id' => $brandId,
                'sort' => $sort,
            ]);
        }
    }

    public static function defaultFor(Brand|int $brand): ?self
    {
        $brandId = $brand instanceof Brand ? (int) $brand->getKey() : $brand;

        return static::query()->forBrand($brandId)->where('is_default', true)->first()
            ?? static::query()->forBrand($brandId)->ordered()->first();
    }

    public static function wonFor(Brand|int $brand): ?self
    {
        $brandId = $brand instanceof Brand ? (int) $brand->getKey() : $brand;

        return static::query()->forBrand($brandId)->where('is_won', true)->first();
    }
}
