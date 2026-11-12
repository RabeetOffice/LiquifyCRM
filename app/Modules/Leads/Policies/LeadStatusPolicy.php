<?php

declare(strict_types=1);

namespace App\Modules\Leads\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Models\LeadStatus;

/**
 * Pipeline stages (user request, 12 Nov 2026): Admins and the brand's
 * Managers add, rename, colour, move and remove the stages of that brand
 * only. Everyone in the brand still sees and uses them; this is about
 * changing the list. New, Won and Lost are never removed.
 */
final class LeadStatusPolicy
{
    public function viewAny(User $user): bool
    {
        $brand = app(CurrentBrand::class)->get();

        return $user->isActive()
            && $brand !== null
            && $user->isMemberOf($brand)
            && ($user->isGlobalAdmin() || $user->isManagerIn($brand));
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, LeadStatus $status): bool
    {
        return $this->viewAny($user) && app(CurrentBrand::class)->is((int) $status->brand_id);
    }

    public function delete(User $user, LeadStatus $status): bool
    {
        return $this->update($user, $status) && ! $status->isFixed();
    }
}
