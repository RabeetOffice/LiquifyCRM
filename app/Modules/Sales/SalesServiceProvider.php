<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ClientFile;
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

    protected function bootModule(): void
    {
        // A client's contract or a zip of their material is bigger than the 12 MB
        // Livewire lets through on its own. Each screen still checks its own limit.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.ClientFile::MAX_KB]]);
    }
}
