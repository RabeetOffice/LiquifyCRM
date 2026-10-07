<?php

declare(strict_types=1);

namespace App\Modules\Brands\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Core\Enums\Permission;

final class InvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::UsersManage->value);
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return $user->isGlobalAdmin() || $user->getKey() === $invitation->invited_by;
    }

    public function create(User $user): bool
    {
        return $user->isGlobalAdmin() || $user->can(Permission::UsersManage->value);
    }

    public function delete(User $user, Invitation $invitation): bool
    {
        return $user->isGlobalAdmin() || $user->getKey() === $invitation->invited_by;
    }
}
