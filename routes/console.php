<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler (one Hostinger cron: * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
| The database queue is drained by the scheduler itself, so no always-on
| worker is needed on shared hosting. Each entry below is monitored by
| spatie/laravel-schedule-monitor once `schedule-monitor:sync` has run.
*/

// Queue: process jobs for up to 55 seconds every minute.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=30')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->doNotMonitor();

// Cron heartbeat shown on the System screen.
Schedule::command('liquify:heartbeat')->everyMinute()->doNotMonitor();

// Application health (DB, cache, queue, schedule, disk, debug mode).
Schedule::command('health:check')->everyFiveMinutes()->doNotMonitor();
Schedule::command('health:schedule-check-heartbeat')->everyMinute()->doNotMonitor();
Schedule::command('health:queue-check-heartbeat')->everyMinute()->doNotMonitor();

// Websites: read new leads in pull mode, and the per-site health checks.
Schedule::command('sites:pull')->everyFiveMinutes()->doNotMonitor();
Schedule::command('sites:check')->everyThirtyMinutes()->doNotMonitor();

// Facebook: renew the connection token before its 60 days run out.
Schedule::command('meta:refresh-tokens')->dailyAt('03:15')->doNotMonitor();

// Brands: refresh each favicon weekly in case the website changed it.
Schedule::command('brands:refresh-identity')->weeklyOn(1, '04:00')->doNotMonitor();

// Backups: daily database backup, then prune according to config/backup.php.
Schedule::command('backup:clean')->dailyAt('01:30')->graceTimeInMinutes(60);
Schedule::command('backup:run --only-db')->dailyAt('02:00')->graceTimeInMinutes(60);

// Housekeeping.
Schedule::command('schedule-monitor:clean-log')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('queue:prune-batches --hours=48')->daily();
