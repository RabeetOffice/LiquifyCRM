<?php

declare(strict_types=1);

namespace App\Modules\Sites\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Sites\Models\Site;

/**
 * "Sites and health" row of the screens table: a Manager may look, a
 * Super Admin or Admin connects, edits and removes websites.
 */
final class SitePolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        $brand = app(CurrentBrand::class)->get();

        return $brand !== null && $user->isManagerIn($brand);
    }

    public function view(User $user, Site $site): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        return $this->viewAny($user) && app(CurrentBrand::class)->is($site->brand_id);
    }

    public function create(User $user): bool
    {
        return $user->isGlobalAdmin();
    }

    public function update(User $user, Site $site): bool
    {
        return $user->isGlobalAdmin();
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->isGlobalAdmin();
    }
}
