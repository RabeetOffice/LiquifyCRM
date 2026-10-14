<?php

declare(strict_types=1);

namespace App\Modules\Brands\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;

/**
 * One person in several brands is an Admin's decision (user decision, 14 Oct
 * 2026): only Admins give someone access to more brands, on the People
 * screen, with a role per brand. A Manager adds their own people to their
 * own brand, as before, but cannot pull in somebody who already works in
 * another brand, nor change someone who is already in theirs by adding them
 * again.
 */
final class BrandSharing
{
    /** Why the actor may not add this person to the brand, or null when they may. */
    public static function refusal(User $actor, ?User $person, Brand $brand): ?string
    {
        if ($actor->isGlobalAdmin() || $person === null) {
            return null;
        }

        if ($person->isGlobalAdmin()) {
            return 'This person is an Admin and already sees every brand.';
        }

        $brands = Membership::query()->acrossBrands()->where('user_id', $person->getKey())->pluck('brand_id')->map(fn ($id): int => (int) $id);

        if ($brands->contains((int) $brand->getKey())) {
            return 'This person is already in '.$brand->name.'.';
        }

        if ($brands->isNotEmpty()) {
            return 'This person already works in another brand. Only an Admin can give someone access to more than one brand.';
        }

        return null;
    }
}
