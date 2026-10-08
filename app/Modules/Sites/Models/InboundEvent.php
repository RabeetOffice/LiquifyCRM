<?php

declare(strict_types=1);

namespace App\Modules\Sites\Models;

use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Enums\InboundStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The raw copy of one incoming lead, kept before anything is processed.
 * A failed event keeps its error and can be retried, so no lead is lost.
 *
 * @property int $id
 * @property string $source
 * @property int|null $source_id
 * @property int|null $brand_id
 * @property string|null $external_id
 * @property array<string, mixed> $payload
 * @property InboundStatus $status
 * @property string|null $error
 * @property int $attempts
 * @property int|null $lead_id
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 */
class InboundEvent extends Model
{
    use Prunable;

    public const SOURCE_SITE = 'site';

    /** @var array<string, class-string> source => queued job that takes the event id */
    private static array $processors = [];

    /**
     * Each channel registers the job that turns its events into leads or
     * messages, so the event log can retry any of them.
     *
     * @param  class-string  $job
     */
    public static function processWith(string $source, string $job): void
    {
        self::$processors[$source] = $job;
    }

    /** Run (or queue) the job of this event's channel again. */
    public function process(bool $sync = false): void
    {
        $job = self::$processors[$this->source] ?? null;

        if ($job === null) {
            $this->markFailed('Nothing can process events from "'.$this->source.'".');

            return;
        }

        $sync ? $job::dispatchSync((int) $this->getKey()) : $job::dispatch((int) $this->getKey());
    }

    protected $fillable = ['source', 'source_id', 'brand_id', 'external_id', 'payload', 'status', 'error', 'attempts', 'lead_id', 'processed_at'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => InboundStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withoutGlobalScopes();
    }

    /**
     * Retention from the document: processed events go after 14 days, failed ones
     * (which may still be retried) after 30.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where(fn (Builder $query): Builder => $query
            ->where(fn (Builder $done): Builder => $done->where('status', InboundStatus::Done->value)->where('created_at', '<', now()->subDays(14)))
            ->orWhere('created_at', '<', now()->subDays(30)));
    }

    public function isTest(): bool
    {
        return (bool) ($this->payload['test'] ?? false);
    }

    public function markDone(?Lead $lead = null): void
    {
        $this->forceFill([
            'status' => InboundStatus::Done,
            'error' => null,
            'lead_id' => $lead?->getKey(),
            'processed_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => InboundStatus::Failed,
            'error' => mb_substr($error, 0, 2000),
            'processed_at' => now(),
        ])->save();
    }
}
