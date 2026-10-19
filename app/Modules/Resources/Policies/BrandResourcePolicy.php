<?php

declare(strict_types=1);

namespace App\Modules\Resources\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Resources\Models\BrandResource;
use App\Modules\Resources\Support\BrandResources;

/**
 * Everyone in a brand opens and downloads its resources; Admins and the
 * brand's Managers add, change and remove them (19 Oct 2026).
 */
final class BrandResourcePolicy
{
    public function viewAny(User $user): bool
    {
        $brand = app(CurrentBrand::class)->get();

        return BrandResources::ready() && $user->isActive() && $brand !== null && $user->isMemberOf($brand);
    }

    public function view(User $user, BrandResource $resource): bool
    {
        return $this->viewAny($user) && app(CurrentBrand::class)->is($resource->brand_id);
    }

    public function create(User $user): bool
    {
        $brand = app(CurrentBrand::class)->get();

        return $this->viewAny($user) && $brand !== null && ($user->isGlobalAdmin() || $user->isManagerIn($brand));
    }

    public function update(User $user, BrandResource $resource): bool
    {
        return $this->view($user, $resource) && $this->create($user);
    }

    public function delete(User $user, BrandResource $resource): bool
    {
        return $this->update($user, $resource);
    }
}
