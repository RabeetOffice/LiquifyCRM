<?php

declare(strict_types=1);

namespace App\Modules\Brands\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\Permission;

/**
 * Members screen: Admins manage Managers and Agents of any brand; a Manager
 * manages the Agents of the departments they lead inside the current brand.
 */
final class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::UsersManage->value);
    }

    public function view(User $user, Membership $membership): bool
    {
        if ($user->isGlobalAdmin() || $user->getKey() === $membership->user_id) {
            return true;
        }

        return $this->managesMembership($user, $membership);
    }

    public function create(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::UsersManage->value);
    }

    public function update(User $user, Membership $membership): bool
    {
        if ($user->getKey() === $membership->user_id) {
            return false;
        }

        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $this->managesMembership($user, $membership);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return $this->update($user, $membership);
    }

    /**
     * Managers may only touch Agents who work in a department they manage.
     */
    private function managesMembership(User $user, Membership $membership): bool
    {
        if ($membership->role !== BrandRole::Agent || ! $user->can(Permission::UsersManage->value)) {
            return false;
        }

        $managed = $user->managedDepartmentIds($membership->brand_id);

        if ($managed === []) {
            return false;
        }

        $member = $membership->user;

        return $member !== null && $member->departments()
            ->acrossBrands()
            ->whereIn('departments.id', $managed)
            ->exists();
    }
}
