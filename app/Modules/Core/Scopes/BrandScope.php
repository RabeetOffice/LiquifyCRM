<?php

declare(strict_types=1);

namespace App\Modules\Core\Scopes;

use App\Modules\Core\Support\CurrentBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Layer 1 of brand isolation: every brand-owned query is limited to the
 * current brand whenever one is resolved (from the /b/{brand} URL or set
 * explicitly in a job). Without a current brand nothing is filtered, so
 * global admin screens and queue jobs can query across brands on purpose.
 */
final class BrandScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $brandId = app(CurrentBrand::class)->id();

        if ($brandId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('brand_id'), $brandId);
    }
}
