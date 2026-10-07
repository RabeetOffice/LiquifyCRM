<?php

declare(strict_types=1);

namespace App\Modules\Brands\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\Permission;

final class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->isMemberOf($brand);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BrandsManage->value);
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->can(Permission::BrandsManage->value);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $user->can(Permission::BrandsManage->value);
    }

    public function restore(User $user, Brand $brand): bool
    {
        return $user->can(Permission::BrandsManage->value);
    }

    public function forceDelete(User $user, Brand $brand): bool
    {
        return $user->isSuperAdmin();
    }
}
