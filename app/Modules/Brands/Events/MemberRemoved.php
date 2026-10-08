<?php

declare(strict_types=1);

namespace App\Modules\Brands\Events;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use Illuminate\Foundation\Events\Dispatchable;

/** Someone lost access to a brand; other modules release what they held. */
final class MemberRemoved
{
    use Dispatchable;

    public function __construct(
        public readonly Brand $brand,
        public readonly User $user,
        public readonly BrandRole $role,
        public readonly ?User $by = null,
    ) {}
}
