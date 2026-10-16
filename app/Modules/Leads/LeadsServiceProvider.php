<?php

declare(strict_types=1);

namespace App\Modules\Leads;

use App\Modules\Brands\Events\MemberRemoved;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Leads\Console\EmptyBinCommand;
use App\Modules\Leads\Listeners\ReturnLeadsToQueue;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Models\Task;
use App\Modules\Leads\Policies\LeadPolicy;
use App\Modules\Leads\Policies\TaskPolicy;
use Illuminate\Support\Facades\Event;

final class LeadsServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Leads';

    protected array $commands = [
        EmptyBinCommand::class,
    ];

    protected array $policies = [
        Lead::class => LeadPolicy::class,
        Task::class => TaskPolicy::class,
    ];

    protected function bootModule(): void
    {
        Brand::created(fn (Brand $brand) => LeadStatus::createDefaultsFor($brand));

        Event::listen(MemberRemoved::class, ReturnLeadsToQueue::class);
    }
}
