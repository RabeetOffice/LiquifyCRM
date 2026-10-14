<?php

declare(strict_types=1);

namespace App\Modules\System\Livewire\Admin;

use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Jobs\RunBackupJob;
use App\Modules\System\Jobs\RunDatabaseUpdateJob;
use App\Modules\System\Models\Deployment;
use App\Modules\System\Support\ReleaseManager;
use App\Modules\System\Support\SystemStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Health\ResultStores\ResultStore;
use Throwable;

/**
 * System screen (Super Admin only): version, deployments, pending database
 * updates with the "Backup and run database update" button, queue and cron
 * health, backups and release rollback.
 */
#[Layout('components.layouts.app')]
#[Title('System')]
final class SystemPage extends Component
{
    use WithConfirmation;
    use WithPagination;
    use WithToasts;

    public ?int $viewingLog = null;

    public function paginationView(): string
    {
        return 'pagination.lq';
    }

    public function databaseUpdate(): void
    {
        $status = app(SystemStatus::class);
        $pending = $status->pendingMigrations();

        $this->askConfirmation(
            'runDatabaseUpdate',
            [],
            'Back up and update the database',
            $pending !== []
                ? 'A database dump is taken first. Then these updates run: '.implode(', ', $pending).'.'
                : 'There are no pending database updates. A backup will still be taken and the update command will confirm nothing is left to run.',
            'Back up and update',
            'primary',
            password: true,
        );
    }

    public function runDatabaseUpdate(): void
    {
        $this->ensureConfirmed();

        if (app(SystemStatus::class)->runningDeployment() !== null) {
            $this->toastWarning('Something is already running');

            return;
        }

        $deployment = Deployment::query()->create([
            'type' => DeploymentType::DatabaseUpdate,
            'status' => DeploymentStatus::Pending,
            'triggered_by' => auth()->id(),
            'log' => 'Queued from the System screen.',
        ]);

        Audit::log('system.database_update_requested', $deployment, [], 'Database update requested', logName: 'system');

        RunDatabaseUpdateJob::dispatch($deployment);

        $this->toastSuccess('Database update queued', config('queue.default') === 'sync' ? 'Finished. The log is below.' : 'It starts within a minute when the queue runs. This page refreshes itself.');
    }

    public function backupNow(): void
    {
        $this->askConfirmation('runBackup', [], 'Back up now?', 'Creates a database-only backup on the backups disk.', 'Back up', 'primary');
    }

    public function runBackup(): void
    {
        $deployment = Deployment::query()->create([
            'type' => DeploymentType::Backup,
            'status' => DeploymentStatus::Pending,
            'triggered_by' => auth()->id(),
            'log' => 'Queued from the System screen.',
        ]);

        RunBackupJob::dispatch($deployment);

        $this->toastSuccess('Backup queued');
    }

    public function rollback(): void
    {
        $releases = app(ReleaseManager::class);

        $this->askConfirmation(
            'runRollback',
            [],
            'Roll back the code',
            'Switches "current" from release '.($releases->currentRelease() ?? '?').' back to '.($releases->previousRelease() ?? '?').'. The database is not changed; restore a backup separately if a migration must be undone.',
            'Roll back',
            'danger',
            password: true,
        );
    }

    public function runRollback(): void
    {
        $this->ensureConfirmed();

        $releases = app(ReleaseManager::class);

        try {
            $from = $releases->currentRelease();
            $to = $releases->rollback();

            Deployment::query()->create([
                'type' => DeploymentType::Rollback,
                'status' => DeploymentStatus::Success,
                'release' => $to,
                'started_at' => now(),
                'finished_at' => now(),
                'triggered_by' => auth()->id(),
                'log' => "Switched current from {$from} to {$to}.",
            ]);

            Audit::log('system.rolled_back', null, ['from' => $from, 'to' => $to], "Rolled back to release {$to}", logName: 'system');

            $this->toastSuccess("Rolled back to release {$to}");
        } catch (Throwable $exception) {
            $this->toastDanger('Rollback failed', $exception->getMessage());
        }
    }

    public function runHealthChecks(): void
    {
        Artisan::call('health:check');

        $this->toastSuccess('Health checks finished');
    }

    public function retryFailedJobs(): void
    {
        $this->askConfirmation('runRetryFailedJobs', [], 'Retry every failed job?', null, 'Retry', 'primary');
    }

    public function runRetryFailedJobs(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        $this->toastSuccess('Failed jobs queued again');
    }

    public function render(): View
    {
        $status = app(SystemStatus::class);
        $releases = app(ReleaseManager::class);
        $running = $status->runningDeployment();

        return view('system::livewire.admin.system-page', [
            'status' => $status,
            'releases' => $releases,
            'pending' => $status->pendingMigrations(),
            'running' => $running,
            'health' => $this->healthResults(),
            'backups' => $this->backups(),
            'deployments' => Deployment::query()->with('triggeredBy')->latest('id')->paginate(10),
            'log' => $this->viewingLog ? Deployment::query()->find($this->viewingLog) : null,
            'polling' => $running !== null,
        ]);
    }

    /**
     * @return Collection<int, array{label: string, status: string, summary: string, message: string}>
     */
    private function healthResults(): Collection
    {
        try {
            $results = app(ResultStore::class)->latestResults();
        } catch (Throwable) {
            return collect();
        }

        if ($results === null) {
            return collect();
        }

        return collect($results->storedCheckResults)->map(fn ($result): array => [
            'label' => (string) $result->label,
            'status' => (string) $result->status,
            'summary' => (string) $result->shortSummary,
            'message' => (string) $result->notificationMessage,
        ])->values();
    }

    /**
     * @return Collection<int, array{name: string, size: string, date: CarbonImmutable}>
     */
    private function backups(): Collection
    {
        try {
            $disk = Storage::disk('backups');
            $files = array_values(array_filter($disk->allFiles(), fn (string $file): bool => str_ends_with($file, '.zip')));
        } catch (Throwable) {
            return collect();
        }

        $backups = array_map(fn (string $file): array => [
            'name' => $file,
            'size' => $this->humanSize($disk->size($file)),
            'date' => now()->setTimestamp($disk->lastModified($file)),
        ], $files);

        usort($backups, fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return collect(array_slice($backups, 0, 10));
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return number_format($size, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
