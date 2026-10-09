<?php

declare(strict_types=1);

namespace App\Modules\System\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use App\Modules\System\Models\Deployment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Update system". New code arrives on the server by itself when it is
 * pushed; what is left for an Admin is to apply it: back up the database,
 * run the pending database changes, clear the caches and record which
 * version is now installed. It runs inside the request, so it needs neither
 * the queue nor the scheduler.
 *
 * An update is ready when the version in the changelog differs from the
 * installed one, or when database changes are waiting.
 */
final class SystemUpdater
{
    private const FLAG = 'liquify.update.flag.';

    public function __construct(private readonly SystemStatus $status, private readonly DatabaseBackup $backup) {}

    /** The version of the code on the server (top of the changelog). */
    public function codeVersion(): string
    {
        return Changelog::latestVersion();
    }

    /** The version recorded by the last successful update; null before the first one. */
    public function installedVersion(): ?string
    {
        $version = $this->lastUpdate()?->release;

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function lastUpdate(): ?Deployment
    {
        return Deployment::query()
            ->where('type', DeploymentType::Update->value)
            ->where('status', DeploymentStatus::Success->value)
            ->latest('id')
            ->first();
    }

    /** The most recent attempt, when it failed and nothing succeeded after it. */
    public function lastFailure(): ?Deployment
    {
        $latest = Deployment::query()->where('type', DeploymentType::Update->value)->latest('id')->first();

        return $latest !== null && $latest->status === DeploymentStatus::Failed ? $latest : null;
    }

    /** @return list<string> */
    public function pendingMigrations(): array
    {
        return $this->status->pendingMigrations();
    }

    public function available(): bool
    {
        return $this->pendingMigrations() !== [] || $this->installedVersion() !== $this->codeVersion();
    }

    /**
     * Apply the update now. With pending database changes a backup is taken
     * first; if it cannot be made nothing is changed, unless the Admin asked
     * to go on without one.
     */
    public function run(User $by, bool $withoutBackup = false): Deployment
    {
        @set_time_limit(600);

        $from = $this->installedVersion();
        $to = $this->codeVersion();
        $pending = $this->pendingMigrations();

        $deployment = Deployment::query()->create([
            'type' => DeploymentType::Update,
            'status' => DeploymentStatus::Running,
            'release' => $to,
            'started_at' => now(),
            'triggered_by' => $by->getKey(),
            'pending_migrations' => $pending,
            'meta' => ['from' => $from, 'to' => $to],
            'log' => 'System update to version '.$to.' started by '.$by->name.'.',
        ]);

        try {
            if ($pending === []) {
                $deployment->appendLog('No database changes in this update.');
            } else {
                $deployment->appendLog(count($pending).' database '.str('change')->plural(count($pending)).' to apply: '.implode(', ', $pending));

                if ($withoutBackup) {
                    $deployment->appendLog('Step 1/3  Backup skipped: the Admin chose to update without one.');
                } else {
                    $deployment->appendLog('Step 1/3  Backing up the database');
                    $backup = $this->backup->run();
                    $deployment->appendLog($backup['output'] !== '' ? $backup['output'] : ($backup['ok'] ? 'Backup finished.' : 'The backup command gave no output.'));

                    if (! $backup['ok']) {
                        $deployment->forceFill(['meta' => ['from' => $from, 'to' => $to, 'backup_failed' => true]])->save();
                        $deployment->markFailed('The database backup could not be made, so nothing was changed.');
                        self::forgetFlag();

                        return $deployment;
                    }
                }

                $deployment->appendLog('Step 2/3  Applying the database changes');
                $exit = Artisan::call('migrate', ['--force' => true]);
                $deployment->appendLog(trim(Artisan::output()));

                if ($exit !== 0) {
                    $deployment->markFailed('The database changes stopped with an error.');
                    self::forgetFlag();

                    return $deployment;
                }
            }

            $deployment->appendLog('Step 3/3  Clearing the caches');
            Artisan::call('optimize:clear');

            $after = $this->pendingMigrations();
            $ran = array_values(array_diff($pending, $after));

            $deployment->forceFill(['migrations_run' => $ran, 'pending_migrations' => $after])->save();
            $deployment->appendLog('Done. LiquifyCRM is on version '.$to.'.');
            $deployment->markSuccess();

            Audit::log('system.updated', $deployment, ['from' => $from, 'to' => $to, 'migrations_run' => $ran], 'System updated to version '.$to, causer: $by, logName: 'system');
        } catch (Throwable $exception) {
            $deployment->markFailed($exception->getMessage());
            report($exception);
        }

        self::forgetFlag();

        return $deployment;
    }

    /**
     * Whether an update is ready, for the bar and the menu badge. Kept for a
     * minute so it does not cost a look at the migrations on every page.
     *
     * @return array{available: bool, version: string, pending: int}
     */
    public static function flag(): array
    {
        $version = Changelog::latestVersion();

        try {
            return Cache::remember(self::FLAG.$version, 60, function () use ($version): array {
                $updater = app(self::class);

                return ['available' => $updater->available(), 'version' => $version, 'pending' => count($updater->pendingMigrations())];
            });
        } catch (Throwable) {
            // A brand-new install without its tables yet: nothing to announce.
            return ['available' => false, 'version' => $version, 'pending' => 0];
        }
    }

    public static function forgetFlag(): void
    {
        Cache::forget(self::FLAG.Changelog::latestVersion());
    }
}
