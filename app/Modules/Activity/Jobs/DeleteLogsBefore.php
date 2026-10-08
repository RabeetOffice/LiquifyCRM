<?php

declare(strict_types=1);

namespace App\Modules\Activity\Jobs;

use App\Modules\Activity\Support\LogArchiver;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Core\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * "Delete logs before [date]" (Super Admin): the period is archived first,
 * then deleted in small batches from the queue. The deletion is logged.
 */
final class DeleteLogsBefore implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $before, public readonly int $userId) {}

    public function handle(LogArchiver $archiver): void
    {
        $user = User::query()->find($this->userId);

        try {
            $deleted = $archiver->deleteBefore(CarbonImmutable::parse($this->before));
        } catch (Throwable $exception) {
            $user?->notify(new AppNotification('Logs were not deleted', $exception->getMessage(), 'danger', url('/admin/logs'), actionLabel: 'Open Logs'));

            return;
        }

        Audit::log('system.logs_deleted', null, ['before' => $this->before, 'rows' => $deleted], 'Logs before '.$this->before.' deleted ('.number_format($deleted).' rows)', causer: $user, logName: 'system');

        $user?->notify(new AppNotification(
            'Logs deleted',
            number_format($deleted).' '.str('entry')->plural($deleted).' before '.$this->before.' left the database. They remain in the daily archive files.',
            'success',
            url('/admin/logs'),
            actionLabel: 'Open Logs',
        ));
    }
}
