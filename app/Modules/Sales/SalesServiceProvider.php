<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Policies\ClientPolicy;
use App\Modules\Sales\Policies\SalePolicy;

final class SalesServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Sales';

    protected array $policies = [
        Client::class => ClientPolicy::class,
        Sale::class => SalePolicy::class,
    ];
}
