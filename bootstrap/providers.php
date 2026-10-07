<?php

declare(strict_types=1);
use App\Modules\Activity\ActivityServiceProvider;
use App\Modules\Auth\AuthServiceProvider;
use App\Modules\Brands\BrandsServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Departments\DepartmentsServiceProvider;
use App\Modules\System\SystemServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
    CoreServiceProvider::class,
    AuthServiceProvider::class,
    BrandsServiceProvider::class,
    DepartmentsServiceProvider::class,
    ActivityServiceProvider::class,
    SystemServiceProvider::class,
];
