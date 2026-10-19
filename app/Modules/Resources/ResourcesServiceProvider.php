<?php

declare(strict_types=1);

namespace App\Modules\Resources;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Resources\Models\BrandResource;
use App\Modules\Resources\Policies\BrandResourcePolicy;

final class ResourcesServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Resources';

    protected array $policies = [
        BrandResource::class => BrandResourcePolicy::class,
    ];
}
