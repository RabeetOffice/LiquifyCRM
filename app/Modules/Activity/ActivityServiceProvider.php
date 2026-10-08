<?php

declare(strict_types=1);

namespace App\Modules\Activity;

use App\Modules\Activity\Console\ArchiveLogsCommand;
use App\Modules\Core\ModuleServiceProvider;

final class ActivityServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Activity';

    protected array $commands = [
        ArchiveLogsCommand::class,
    ];
}
