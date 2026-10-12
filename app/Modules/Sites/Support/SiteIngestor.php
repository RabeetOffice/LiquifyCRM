<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

use App\Modules\Core\Support\RunSoon;
use App\Modules\Sites\Jobs\IngestLead;
use App\Modules\Sites\Models\InboundEvent;
use App\Modules\Sites\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Stores the raw copy of one incoming website lead and queues the job that
 * turns it into a lead. A lead that arrives twice (same site, same external
 * id) is stored once.
 */
final class SiteIngestor
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function accept(Site $site, array $payload, bool $sync = false): InboundEvent
    {
        $externalId = trim((string) ($payload['external_id'] ?? ''));
        $externalId = $externalId === '' ? null : mb_substr($externalId, 0, 120);

        if ($externalId !== null && ($existing = $this->find($site, $externalId)) !== null) {
            return $existing;
        }

        try {
            $event = InboundEvent::query()->create([
                'source' => InboundEvent::SOURCE_SITE,
                'source_id' => $site->getKey(),
                'brand_id' => $site->brand_id,
                'external_id' => $externalId,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two copies of the same lead arrived at the same moment.
            return $this->find($site, (string) $externalId) ?? throw new \RuntimeException('The lead could not be stored.');
        }

        // Not left for the scheduler's next minute: the lead is on screen seconds after the website sent it.
        $sync ? IngestLead::dispatchSync($event->getKey()) : RunSoon::dispatch(new IngestLead((int) $event->getKey()));

        return $event;
    }

    private function find(Site $site, string $externalId): ?InboundEvent
    {
        return InboundEvent::query()
            ->where('source', InboundEvent::SOURCE_SITE)
            ->where('source_id', $site->getKey())
            ->where('external_id', $externalId)
            ->first();
    }
}
