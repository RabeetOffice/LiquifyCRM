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
        return $user->isGlobalAdmin() || $user->isIt() || $user->can(Permission::UsersManage->value);
    }

    /**
     * Set somebody's password and look after their two-step (authenticator)
     * from the People and Two-step screens (user request, 27 Oct 2026). Admins
     * do it for everybody but a Super Admin; IT only for Managers, Agents and
     * Website leads people, never for an Admin, a Super Admin or another IT
     * person. Your own goes through your profile, where the old one is asked.
     */
    public function changePassword(User $user, User $target): bool
    {
        if ($user->is($target) || ! $user->canManageSystem() || $target->isSuperAdmin()) {
            return false;
        }

        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $target->role === null || $target->isWebsiteLeads();
    }

    public function manageTwoStep(User $user, User $target): bool
    {
        return $this->changePassword($user, $target);
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
        return ($user->isGlobalAdmin() && $user->isActive()) || $user->can(Permission::UsersManage->value);
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
     * Which brands a Manager or Agent works in, and their role and department
     * in each: an Admin's decision only, on the People screen. A Manager adds
     * people to their own brand on its Members screen (see BrandSharing).
     */
    public function shareBrands(User $user, User $target): bool
    {
        // IT and Website leads people open no brand, so only Managers and Agents (no global role) get one.
        return $user->isGlobalAdmin() && $user->isActive() && $target->role === null;
    }

    /**
     * Who may hand out a global role. Admins and Super Admins can make someone
     * an Admin; only a Super Admin can make another Super Admin.
     */
    public function assignGlobalRole(User $user, ?GlobalRole $role): bool
    {
        // A suspended Admin hands out nothing, whichever screen asks.
        if (! $user->isActive()) {
            return false;
        }

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
