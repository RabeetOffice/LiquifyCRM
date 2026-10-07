<?php

declare(strict_types=1);

namespace App\Modules\Brands\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use Illuminate\Support\Facades\DB;

/**
 * Gives a user access to a brand with a role and, optionally, places them in
 * a department. Re-running updates the role instead of duplicating.
 */
final class AddMemberToBrand
{
    public function handle(
        Brand $brand,
        User $user,
        BrandRole $role,
        ?Department $department = null,
        ?DepartmentRole $departmentRole = null,
        ?User $by = null,
    ): Membership {
        return DB::transaction(function () use ($brand, $user, $role, $department, $departmentRole, $by): Membership {
            /** @var Membership $membership */
            $membership = Membership::query()
                ->acrossBrands()
                ->firstOrNew(['brand_id' => $brand->getKey(), 'user_id' => $user->getKey()]);

            $isNew = ! $membership->exists;

            $membership->fill([
                'role' => $role,
                'status' => MembershipStatus::Active,
                'invited_by' => $membership->invited_by ?? $by?->getKey(),
                'joined_at' => $membership->joined_at ?? now(),
            ])->save();

            if ($department !== null) {
                $department->addMember($user, $departmentRole ?? DepartmentRole::from($role->value === 'manager' ? 'manager' : 'agent'), $by);
            }

            Audit::log(
                $isNew ? 'members.added' : 'members.role_changed',
                $membership,
                [
                    'user_id' => $user->getKey(),
                    'email' => $user->email,
                    'role' => $role->value,
                    'department_id' => $department?->getKey(),
                    'department_role' => $departmentRole?->value,
                ],
                $isNew ? "Added {$user->name} as {$role->getLabel()}" : "Changed {$user->name} to {$role->getLabel()}",
                brand: $brand,
                department: $department,
                causer: $by,
                logName: 'members',
            );

            return $membership;
        });
    }
}
