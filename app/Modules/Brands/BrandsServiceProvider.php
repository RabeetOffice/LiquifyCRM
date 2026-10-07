<?php

declare(strict_types=1);

namespace App\Modules\Brands;

use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Brands\Models\Membership;
use App\Modules\Brands\Policies\BrandPolicy;
use App\Modules\Brands\Policies\InvitationPolicy;
use App\Modules\Brands\Policies\MembershipPolicy;
use App\Modules\Core\ModuleServiceProvider;

final class BrandsServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Brands';

    protected array $policies = [
        Brand::class => BrandPolicy::class,
        Membership::class => MembershipPolicy::class,
        Invitation::class => InvitationPolicy::class,
    ];
}
