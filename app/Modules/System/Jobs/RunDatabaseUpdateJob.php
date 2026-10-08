<?php

declare(strict_types=1);

namespace App\Modules\System\Jobs;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\System\Models\Deployment;
use App\Modules\System\Support\SystemStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * "Backup and run database update": a database dump with spatie/laravel-backup
 * first, then migrate --force, with every line of output kept on the
 * deployment record. If the backup fails nothing is migrated.
 */
final class RunDatabaseUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly Deployment $deployment) {}

    public function handle(SystemStatus $status): void
    {
        $deployment = $this->deployment;
        $deployment->markRunning();

        try {
            $pendingBefore = $status->pendingMigrations();
            $deployment->appendLog('Pending migrations: '.(count($pendingBefore) > 0 ? implode(', ', $pendingBefore) : 'none'));

            $deployment->appendLog('Step 1/3  Backing up the database (spatie/laravel-backup)');
            $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
            $deployment->appendLog(trim(Artisan::output()));

            if ($exit !== 0) {
                $deployment->markFailed('Backup failed, so the database update was not started.');
                $this->notify($deployment, false);

                return;
            }

            $deployment->appendLog('Step 2/3  Running migrations (php artisan migrate --force)');
            $exit = Artisan::call('migrate', ['--force' => true]);
            $deployment->appendLog(trim(Artisan::output()));

            if ($exit !== 0) {
                $deployment->markFailed('migrate --force returned a non-zero exit code.');
                $this->notify($deployment, false);

                return;
            }

            $deployment->appendLog('Step 3/3  Refreshing caches');
            Artisan::call('optimize:clear');
            $deployment->appendLog(trim(Artisan::output()));

            if (app()->isProduction()) {
                Artisan::call('optimize');
                $deployment->appendLog(trim(Artisan::output()));
            }

            $pendingAfter = $status->pendingMigrations();
            $ran = array_values(array_diff($pendingBefore, $pendingAfter));

            $deployment->forceFill([
                'migrations_run' => $ran,
                'pending_migrations' => $pendingAfter,
            ])->save();

            $deployment->appendLog(count($ran) > 0 ? 'Applied: '.implode(', ', $ran) : 'Nothing to migrate.');
            $deployment->markSuccess();

            Audit::log(
                'system.database_updated',
                $deployment,
                ['migrations_run' => $ran],
                'Database update completed',
                causer: $deployment->triggeredBy,
                logName: 'system',
            );

            $this->notify($deployment, true);
        } catch (Throwable $exception) {
            $deployment->markFailed($exception->getMessage());
            report($exception);
            $this->notify($deployment, false);
        }
    }

    private function notify(Deployment $deployment, bool $success): void
    {
        $user = $deployment->triggeredBy;

        if ($user === null) {
            return;
        }

        $user->notify(new AppNotification(
            $success ? 'Database update finished' : 'Database update failed',
            $success
                ? count($deployment->migrations_run ?? []).' migration(s) applied after a fresh backup.'
                : 'Open the System screen to read the log.',
            $success ? 'success' : 'danger',
            url('/admin/system'),
            actionLabel: 'Open System',
        ));
    }
}
