<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Scopes\BrandScope;
use App\Modules\Core\Support\CurrentBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        static::addGlobalScope(new BrandScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('brand_id') === null) {
                $model->setAttribute('brand_id', app(CurrentBrand::class)->id());
            }
        });
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Query one brand explicitly, ignoring the current-brand scope.
     */
    public function scopeForBrand(Builder $query, Brand|int $brand): Builder
    {
        $brandId = $brand instanceof Brand ? $brand->getKey() : $brand;

        return $query->withoutGlobalScope(BrandScope::class)
            ->where($this->qualifyColumn('brand_id'), $brandId);
    }

    public function scopeAcrossBrands(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BrandScope::class);
    }
}
