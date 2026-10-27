<?php

declare(strict_types=1);

use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Support\Facades\Schedule;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

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
Schedule::command('sites:pull')->everyMinute()->doNotMonitor();
Schedule::command('sites:check')->everyThirtyMinutes()->doNotMonitor();

// Facebook: renew the connection token before its 60 days run out.
Schedule::command('meta:refresh-tokens')->dailyAt('03:15')->doNotMonitor();

// Facebook and Instagram: the profile name of open leads, which Messenger searches by (a form has only what was typed).
Schedule::command('meta:profile-names')->everyTenMinutes()->withoutOverlapping(10)->runInBackground()->doNotMonitor();

// Brands: refresh each favicon weekly in case the website changed it.
Schedule::command('brands:refresh-identity')->weeklyOn(1, '04:00')->doNotMonitor();

// Backups: daily database backup, then prune according to config/backup.php.
Schedule::command('backup:clean')->dailyAt('01:30')->graceTimeInMinutes(60);
Schedule::command('backup:run')->dailyAt('02:00')->graceTimeInMinutes(60);

// Logs: archive yesterday to a compressed file, then apply the retention settings.
Schedule::command('logs:archive')->dailyAt('00:40')->graceTimeInMinutes(60);

// Raw copies of received leads: done ones go after 14 days, failed ones after 30. The schedule monitor's own log
// goes after its configured days too (version 4 of the package has no clean-log command any more).
Schedule::command('model:prune', ['--model' => [InboundEvent::class, MonitoredScheduledTaskLogItem::class]])->dailyAt('00:55')->doNotMonitor();

// Follow-ups: the owner hears 10, 5 and 1 minutes before (bell, chime, desktop alert, push).
Schedule::command('followups:remind')->everyMinute()->withoutOverlapping(5)->runInBackground()->doNotMonitor();

// New leads by email: one summary per person every three hours, on the hour, instead of an email a lead.
Schedule::command('leads:digest')->cron('0 */3 * * *')->timezone((string) config('liquify.default_timezone'))->withoutOverlapping(30)->runInBackground()->doNotMonitor();

// Leads that have been in the bin for 30 days are deleted for good.
Schedule::command('leads:empty-bin')->dailyAt('00:50')->doNotMonitor();

// Housekeeping.
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('queue:prune-batches --hours=48')->daily();
