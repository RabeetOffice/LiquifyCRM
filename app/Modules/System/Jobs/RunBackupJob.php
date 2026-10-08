<?php

declare(strict_types=1);

namespace App\Modules\System\Jobs;

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use App\Modules\System\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * "Back up now" from the System screen: a database-only backup to the
 * private backups disk, logged on a deployment record.
 */
final class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly Deployment $deployment) {}

    public function handle(): void
    {
        $deployment = $this->deployment;
        $deployment->markRunning();

        try {
            $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
            $deployment->appendLog(trim(Artisan::output()));

            if ($exit !== 0) {
                $deployment->markFailed('backup:run returned a non-zero exit code.');
                $this->notify($deployment, false);

                return;
            }

            $deployment->markSuccess();

            Audit::log('system.backup_created', $deployment, [], 'Database backup created', causer: $deployment->triggeredBy, logName: 'system');

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
            $success ? 'Backup finished' : 'Backup failed',
            $success ? 'The database backup is stored on the backups disk.' : 'Open the System screen to read the log.',
            $success ? 'success' : 'danger',
            url('/admin/system'),
            actionLabel: 'Open System',
        ));
    }
}
