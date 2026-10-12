<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use Illuminate\Database\Eloquent\Builder;

/**
 * The active Agents of a brand a person may hand leads to: Admins all of
 * them, a Manager those in the departments they lead.
 */
final class AssignableAgents
{
    /** @return array<int, string> */
    public function for(User $user, Brand $brand): array
    {
        $query = User::query()
            ->whereHas('memberships', fn (Builder $query): Builder => $query->acrossBrands()->where('brand_id', $brand->getKey())->where('role', BrandRole::Agent->value)->where('status', 'active'))
            ->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereHas('departments', fn (Builder $query): Builder => $query->acrossBrands()->whereIn('departments.id', $user->managedDepartmentIds($brand)));
        }

        return $query->pluck('name', 'id')->all();
    }
}
