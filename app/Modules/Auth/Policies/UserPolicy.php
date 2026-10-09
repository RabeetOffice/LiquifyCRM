<?php

declare(strict_types=1);

namespace App\Modules\Auth\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Support\CurrentBrand;

/**
 * Layer 2 for people. Encodes the "Manage users" row of the permission table
 * and the Super Admin protection rules. Since 9 October an Admin holds the
 * same control as a Super Admin (adds and manages Admins, Managers and
 * Agents); the one difference is that a Super Admin is protected: nobody
 * else can edit, suspend, demote or remove one, only a Super Admin can
 * create another, and the last one can never be removed.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::UsersManage->value);
    }

    public function view(User $user, User $target): bool
    {
        if ($user->is($target) || $user->isGlobalAdmin()) {
            return true;
        }

        return $this->managesTarget($user, $target);
    }

    public function create(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::UsersManage->value);
    }

    public function update(User $user, User $target): bool
    {
        if ($target->isSuperAdmin()) {
            return $user->is($target);
        }

        if ($user->is($target) || $user->isGlobalAdmin()) {
            return true;
        }

        return $this->managesTarget($user, $target);
    }

    public function suspend(User $user, User $target): bool
    {
        if ($user->is($target) || $target->isSuperAdmin()) {
            return false;
        }

        return $user->isGlobalAdmin() || $this->managesTarget($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->is($target) || $target->isSuperAdmin()) {
            return false;
        }

        return $user->isGlobalAdmin() || $this->managesTarget($user, $target);
    }

    public function restore(User $user, User $target): bool
    {
        return $user->isGlobalAdmin();
    }

    public function forceDelete(User $user, User $target): bool
    {
        return false;
    }

    /**
     * Who may hand out a global role. Admins and Super Admins can make someone
     * an Admin; only a Super Admin can make another Super Admin.
     */
    public function assignGlobalRole(User $user, ?GlobalRole $role): bool
    {
        if ($role === GlobalRole::SuperAdmin) {
            return $user->isSuperAdmin();
        }

        return $user->isGlobalAdmin();
    }

    /**
     * A Manager may act on Agents inside the departments they manage within
     * the current brand.
     */
    private function managesTarget(User $user, User $target): bool
    {
        if ($target->isGlobalAdmin()) {
            return false;
        }

        $brandId = app(CurrentBrand::class)->id();

        if ($brandId === null || ! $user->can(Permission::UsersManage->value)) {
            return false;
        }

        $managed = $user->managedDepartmentIds($brandId);

        if ($managed === []) {
            return false;
        }

        return $target->departments()
            ->acrossBrands()
            ->whereIn('departments.id', $managed)
            ->exists();
    }
}
