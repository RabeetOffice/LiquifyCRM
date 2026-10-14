<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Modules\Core\ModuleServiceProvider;
use App\Modules\System\Console\HeartbeatCommand;
use App\Modules\System\Console\SpeedCheckCommand;
use App\Modules\System\Support\ReleaseManager;
use App\Modules\System\Support\SystemStatus;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

final class SystemServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'System';

    protected array $commands = [
        HeartbeatCommand::class,
        SpeedCheckCommand::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SystemStatus::class);
        $this->app->singleton(ReleaseManager::class);
    }

    protected function bootModule(): void
    {
        // LiquifyCRM's own health (System screen): DB, cache, queue, cron, disk.
        $checks = [
            DatabaseCheck::new(),
            CacheCheck::new(),
            QueueCheck::new(),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(3),
        ];

        if (PHP_OS_FAMILY !== 'Windows') {
            $checks[] = UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(80)
                ->failWhenUsedSpaceIsAbovePercentage(92);
        }

        // No "optimized app" check: config, routes and events are never cached on the server on purpose
        // (the site deploys itself on a push, and new code would run against a stale cache), so it could only fail.
        if ($this->app->isProduction()) {
            $checks[] = DebugModeCheck::new();
            $checks[] = EnvironmentCheck::new();
        }

        Health::checks($checks);
    }
}
