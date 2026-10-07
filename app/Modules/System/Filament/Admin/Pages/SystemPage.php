<?php

declare(strict_types=1);

namespace App\Modules\System\Filament\Admin\Pages;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Jobs\RunBackupJob;
use App\Modules\System\Jobs\RunDatabaseUpdateJob;
use App\Modules\System\Models\Deployment;
use App\Modules\System\Support\ReleaseManager;
use App\Modules\System\Support\SystemStatus;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Health\ResultStores\ResultStore;
use Throwable;
use UnitEnum;

/**
 * System screen (Super Admin only): version, deployments, pending database
 * updates with the "Backup and run database update" button, queue and cron
 * health, backups and release rollback.
 */
final class SystemPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System';

    protected static ?string $title = 'System';

    protected static ?string $slug = 'system';

    protected static ?int $navigationSort = 100;

    protected string $view = 'system::filament.pages.system';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    protected function getHeaderActions(): array
    {
        $status = app(SystemStatus::class);
        $releases = app(ReleaseManager::class);

        return [
            Action::make('databaseUpdate')
                ->label('Backup and run database update')
                ->icon(Heroicon::OutlinedCircleStack)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Back up and update the database')
                ->modalDescription(fn (): string => $status->pendingMigrationCount() > 0
                    ? 'A database dump is taken first. Then these updates run: '.implode(', ', $status->pendingMigrations()).'.'
                    : 'There are no pending database updates. A backup will still be taken and the update command will confirm nothing is left to run.')
                ->modalSubmitActionLabel('Back up and update')
                ->schema([
                    TextInput::make('current_password')
                        ->label('Confirm your password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->currentPassword(),
                ])
                ->disabled(fn (): bool => $status->runningDeployment() !== null)
                ->action(function (): void {
                    $deployment = Deployment::query()->create([
                        'type' => DeploymentType::DatabaseUpdate,
                        'status' => DeploymentStatus::Pending,
                        'triggered_by' => auth()->id(),
                        'log' => 'Queued from the System screen.',
                    ]);

                    Audit::log('system.database_update_requested', $deployment, [], 'Database update requested', logName: 'system');

                    RunDatabaseUpdateJob::dispatch($deployment);

                    Notification::make()
                        ->title('Database update queued')
                        ->body(config('queue.default') === 'sync'
                            ? 'Finished. The log is below.'
                            : 'It starts within a minute when the queue runs. This page refreshes itself.')
                        ->success()
                        ->send();
                }),

            Action::make('backupNow')
                ->label('Back up now')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Creates a database-only backup on the backups disk.')
                ->disabled(fn (): bool => $status->runningDeployment() !== null)
                ->action(function (): void {
                    $deployment = Deployment::query()->create([
                        'type' => DeploymentType::Backup,
                        'status' => DeploymentStatus::Pending,
                        'triggered_by' => auth()->id(),
                        'log' => 'Queued from the System screen.',
                    ]);

                    RunBackupJob::dispatch($deployment);

                    Notification::make()->title('Backup queued')->success()->send();
                }),

            Action::make('rollback')
                ->label('Roll back to previous release')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->visible(fn (): bool => $releases->enabled() && $releases->previousRelease() !== null)
                ->requiresConfirmation()
                ->modalHeading('Roll back the code')
                ->modalDescription(fn (): string => 'Switches "current" from release '.($releases->currentRelease() ?? '?').' back to '.($releases->previousRelease() ?? '?').'. The database is not changed; restore a backup separately if a migration must be undone.')
                ->schema([
                    TextInput::make('current_password')
                        ->label('Confirm your password')
                        ->password()
                        ->revealable()
                        ->required()
                        ->currentPassword(),
                ])
                ->action(function () use ($releases): void {
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

                        Notification::make()->title("Rolled back to release {$to}")->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()->title('Rollback failed')->body($exception->getMessage())->danger()->send();
                    }
                }),

            Action::make('runHealthChecks')
                ->label('Run health checks')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    Artisan::call('health:check');

                    Notification::make()->title('Health checks finished')->success()->send();
                }),

            Action::make('retryFailedJobs')
                ->label('Retry failed jobs')
                ->icon(Heroicon::OutlinedBolt)
                ->color('gray')
                ->visible(fn (): bool => $status->failedJobs() > 0)
                ->requiresConfirmation()
                ->action(function (): void {
                    Artisan::call('queue:retry', ['id' => ['all']]);

                    Notification::make()->title('Failed jobs queued again')->success()->send();
                }),
        ];
    }

    public function getStatus(): SystemStatus
    {
        return app(SystemStatus::class);
    }

    public function getReleases(): ReleaseManager
    {
        return app(ReleaseManager::class);
    }

    /**
     * @return Collection<int, array{label: string, status: string, summary: string, message: string}>
     */
    public function getHealthResults(): Collection
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
    public function getBackups(): Collection
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

    public function isPolling(): bool
    {
        return $this->getStatus()->runningDeployment() !== null;
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
