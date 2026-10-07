<?php

declare(strict_types=1);

namespace App\Modules\System\Models;

use App\Modules\Auth\Models\User;
use App\Modules\System\Enums\DeploymentStatus;
use App\Modules\System\Enums\DeploymentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deploy, database update, backup and rollback history for the System screen.
 *
 * @property int $id
 * @property DeploymentType $type
 * @property DeploymentStatus $status
 * @property string|null $commit_sha
 * @property string|null $branch
 * @property string|null $release
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $log
 * @property list<string>|null $migrations_run
 * @property list<string>|null $pending_migrations
 * @property array<string, mixed>|null $meta
 * @property int|null $triggered_by
 */
class Deployment extends Model
{
    protected $fillable = [
        'type', 'status', 'commit_sha', 'branch', 'release', 'started_at', 'finished_at',
        'log', 'migrations_run', 'pending_migrations', 'meta', 'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => DeploymentType::class,
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'migrations_run' => 'array',
            'pending_migrations' => 'array',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function shortSha(): ?string
    {
        return $this->commit_sha ? substr($this->commit_sha, 0, 7) : null;
    }

    public function appendLog(string $line): void
    {
        $this->log = rtrim((string) $this->log)."\n".'['.now()->format('H:i:s').'] '.rtrim($line);
        $this->save();
    }

    public function markRunning(): void
    {
        $this->forceFill(['status' => DeploymentStatus::Running, 'started_at' => now()])->save();
    }

    public function markSuccess(): void
    {
        $this->forceFill(['status' => DeploymentStatus::Success, 'finished_at' => now()])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->appendLog('FAILED: '.$reason);
        $this->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at, true);
    }
}
