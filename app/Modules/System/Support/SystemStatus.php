<?php

declare(strict_types=1);

namespace App\Modules\System\Support;

use App\Modules\System\Console\HeartbeatCommand;
use App\Modules\System\Models\Deployment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Read-only facts for the System screen: version, pending migrations, queue
 * and cron health. Nothing here changes state.
 */
final class SystemStatus
{
    /**
     * Git commit of the running code: the .release file written by Deployer,
     * or the local git checkout while developing.
     */
    public function version(): string
    {
        $file = base_path((string) config('liquify.version_file'));

        if (is_file($file)) {
            return trim((string) file_get_contents($file)) ?: 'unknown';
        }

        return Cache::remember('liquify.system.git_version', 300, function (): string {
            try {
                $process = Process::fromShellCommandline('git rev-parse --short HEAD', base_path());
                $process->setTimeout(5)->run();

                return $process->isSuccessful() ? trim($process->getOutput()) : 'dev';
            } catch (Throwable) {
                return 'dev';
            }
        });
    }

    /**
     * Migration names that exist on disk but have not been run.
     *
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return [];
        }

        $paths = array_values(array_unique([...$migrator->paths(), database_path('migrations')]));
        $files = $migrator->getMigrationFiles($paths);
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }

    public function pendingMigrationCount(): int
    {
        return count($this->pendingMigrations());
    }

    public function lastDeployment(): ?Deployment
    {
        return Deployment::query()->latest('id')->first();
    }

    public function runningDeployment(): ?Deployment
    {
        return Deployment::query()
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();
    }

    public function pendingJobs(): int
    {
        return Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
    }

    public function failedJobs(): int
    {
        return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
    }

    public function lastCronRun(): ?CarbonImmutable
    {
        $value = Cache::get(HeartbeatCommand::CACHE_KEY);

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /** Healthy when the scheduler fired in the last 3 minutes. */
    public function cronIsHealthy(): bool
    {
        $last = $this->lastCronRun();

        return $last !== null && $last->greaterThan(now()->subMinutes(3));
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return [
            'App version' => Changelog::latestVersion(),
            'Build' => $this->version(),
            'Environment' => (string) app()->environment(),
            'Debug mode' => config('app.debug') ? 'On' : 'Off',
            'PHP' => PHP_VERSION,
            'Laravel' => app()->version(),
            'Database' => DB::connection()->getDriverName().' '.$this->databaseVersion(),
            'Queue driver' => (string) config('queue.default'),
            'Cache driver' => (string) config('cache.default'),
            'Timezone' => (string) config('app.timezone'),
        ];
    }

    private function databaseVersion(): string
    {
        try {
            return (string) DB::connection()->getServerVersion();
        } catch (Throwable) {
            return '';
        }
    }
}
