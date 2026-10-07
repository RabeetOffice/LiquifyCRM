<?php

declare(strict_types=1);

namespace App\Modules\System\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every minute from the scheduler so the System screen can show when
 * the Hostinger cron last fired.
 */
final class HeartbeatCommand extends Command
{
    public const CACHE_KEY = 'liquify.cron.last_run';

    protected $signature = 'liquify:heartbeat';

    protected $description = 'Record that the scheduler ran (shown as cron health on the System screen)';

    public function handle(): int
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
