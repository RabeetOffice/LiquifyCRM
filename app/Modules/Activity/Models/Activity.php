<?php

declare(strict_types=1);

namespace App\Modules\Activity\Models;

use App\Modules\Brands\Models\Brand;
use App\Modules\Departments\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Audit trail row: who, which brand and department, what happened, before
 * and after values, IP address, browser and time.
 *
 * @property int|null $brand_id
 * @property int|null $department_id
 * @property string|null $ip
 * @property string|null $user_agent
 */
class Activity extends SpatieActivity
{
    protected $fillable = ['brand_id', 'department_id', 'ip', 'user_agent'];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeForBrand(Builder $query, Brand|int $brand): Builder
    {
        return $query->where('brand_id', $brand instanceof Brand ? $brand->getKey() : $brand);
    }

    /** @param  Builder<static>  $query */
    public function scopeForDepartments(Builder $query, array $departmentIds): Builder
    {
        return $query->whereIn('department_id', $departmentIds);
    }
}
