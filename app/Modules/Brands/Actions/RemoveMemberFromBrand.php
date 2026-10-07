<?php

declare(strict_types=1);

namespace App\Modules\Brands\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use Illuminate\Support\Facades\DB;

/**
 * Removing someone cuts their access to the brand instantly: the membership,
 * the brand role and every department seat in that brand are removed.
 * (Week 2 adds: their open leads go back to the department queue.)
 */
final class RemoveMemberFromBrand
{
    public function handle(Membership $membership, ?User $by = null): void
    {
        DB::transaction(function () use ($membership, $by): void {
            /** @var Brand $brand */
            $brand = $membership->brand()->withTrashed()->firstOrFail();
            /** @var User $user */
            $user = $membership->user()->firstOrFail();

            $departmentIds = Department::query()
                ->acrossBrands()
                ->where('brand_id', $brand->getKey())
                ->pluck('id');

            $user->departments()->detach($departmentIds->all());

            Audit::log(
                'members.removed',
                $membership,
                ['user_id' => $user->getKey(), 'email' => $user->email, 'role' => $membership->role->value],
                "Removed {$user->name} from {$brand->name}",
                brand: $brand,
                causer: $by,
                logName: 'members',
            );

            $membership->delete();
        });
    }
}
