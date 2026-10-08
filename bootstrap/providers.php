<?php

declare(strict_types=1);
use App\Modules\Activity\ActivityServiceProvider;
use App\Modules\Auth\AuthServiceProvider;
use App\Modules\Brands\BrandsServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Departments\DepartmentsServiceProvider;
use App\Modules\Leads\LeadsServiceProvider;
use App\Modules\Sites\SitesServiceProvider;
use App\Modules\System\SystemServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    CoreServiceProvider::class,
    AuthServiceProvider::class,
    BrandsServiceProvider::class,
    DepartmentsServiceProvider::class,
    LeadsServiceProvider::class,
    SitesServiceProvider::class,
    ActivityServiceProvider::class,
    SystemServiceProvider::class,
];
