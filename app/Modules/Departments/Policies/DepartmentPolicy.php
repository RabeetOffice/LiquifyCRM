<?php

declare(strict_types=1);

namespace App\Modules\Departments\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\Permission;
use App\Modules\Departments\Models\Department;

/**
 * Departments row of the permission table: Super Admin and Admin create,
 * edit and remove; a Manager edits the profile of the departments they lead.
 */
final class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Department $department): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $user->isMemberOf($department->brand_id) && $user->belongsToDepartment($department);
    }

    public function create(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    public function update(User $user, Department $department): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $user->can(Permission::DepartmentsManage->value) && $user->managesDepartment($department);
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->isGlobalAdmin();
    }

    public function restore(User $user, Department $department): bool
    {
        return $user->isGlobalAdmin();
    }

    public function forceDelete(User $user, Department $department): bool
    {
        return $user->isSuperAdmin();
    }

    /** Adding or removing people is an Admin job, or a Manager inside their own department. */
    public function manageMembers(User $user, Department $department): bool
    {
        return $this->update($user, $department);
    }
}
