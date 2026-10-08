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
use App\Modules\Core\Support\CurrentBrand;
use Illuminate\Support\Facades\Route;

final class BrandsServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Brands';

    protected array $policies = [
        Brand::class => BrandPolicy::class,
        Membership::class => MembershipPolicy::class,
        Invitation::class => InvitationPolicy::class,
    ];

    protected function bootModule(): void
    {
        // /b/{brand}: resolve the brand by slug and make it the current brand
        // before any other binding (so {lead}, {department}... are scoped to it).
        // Membership is checked by the brand.member middleware after auth.
        Route::bind('brand', function (string $value): Brand {
            $brand = Brand::query()->where('slug', $value)->first();

            abort_if($brand === null, 404);

            app(CurrentBrand::class)->set($brand);

            return $brand;
        });
    }
}
