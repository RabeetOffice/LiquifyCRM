<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Policies\ClientPolicy;

final class SalesServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Sales';

    protected array $policies = [
        Client::class => ClientPolicy::class,
    ];
}
