<?php

declare(strict_types=1);

namespace App\Modules\Sites\Jobs;

use App\Modules\Brands\Jobs\SendAutoReply;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Exceptions\LeadRejected;
use App\Modules\Sites\Models\InboundEvent;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\SiteLeadNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Every website lead ends here: resolve the site to its brand, normalise the
 * fields, de-duplicate, put the lead in New / Unassigned and notify (all
 * through CreateLead). A failure is written on the event so it can be retried.
 */
final class IngestLead implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $eventId) {}

    public function handle(CreateLead $createLead, SiteLeadNormalizer $normalizer): void
    {
        $event = InboundEvent::query()->find($this->eventId);

        if ($event === null || $event->status === InboundStatus::Done) {
            return;
        }

        $event->increment('attempts');

        try {
            $site = Site::query()->acrossBrands()->with('brand')->find($event->source_id);

            if ($site === null) {
                throw new LeadRejected('This website was removed from LiquifyCRM.');
            }

            if ($event->isTest()) {
                $site->forceFill(['last_test_at' => now()])->save();
                $event->markDone();

                return;
            }

            $lead = app(CurrentBrand::class)->within($site->brand, fn (): Lead => $this->createLead($event, $site, $createLead, $normalizer));

            $event->markDone($lead);
        } catch (Throwable $exception) {
            $event->markFailed($exception->getMessage());

            if (! $exception instanceof LeadRejected) {
                report($exception);
            }
        }
    }

    private function createLead(InboundEvent $event, Site $site, CreateLead $createLead, SiteLeadNormalizer $normalizer): Lead
    {
        $data = $normalizer->normalise($event->payload);

        if ($data['spam']) {
            throw new LeadRejected('Looks like spam: a hidden field that people never see was filled in.');
        }

        if ($data['name'] === null && $data['email'] === null && $data['phone'] === null) {
            throw new LeadRejected('No name, email or phone number was sent, so there is nobody to contact.');
        }

        // Two sites of one brand can both have a row 12, so the site is part of the id.
        $externalId = $event->external_id !== null ? $site->getKey().':'.$event->external_id : null;

        $lead = $externalId === null ? null : Lead::query()
            ->withTrashed()
            ->where('source_type', LeadSource::Website->value)
            ->where('external_id', $externalId)
            ->first();

        $created = false;

        if ($lead === null) {
            $result = $createLead->handle($site->brand, array_filter([
                'source_type' => LeadSource::Website,
                'source_id' => $site->getKey(),
                'external_id' => $externalId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'message' => $data['message'],
                'fields' => $data['fields'] !== [] ? ['website' => $site->name, ...$data['fields']] : ['website' => $site->name],
                'utm' => $data['utm'] !== [] ? $data['utm'] : null,
                'created_at' => $data['created_at'],
            ], fn (mixed $value): bool => $value !== null));

            $lead = $result->lead;
            $created = ! $result->wasMerged;
        }

        $receivedAt = $data['created_at'] ?? now();

        if ($site->last_lead_at === null || $site->last_lead_at->lt($receivedAt)) {
            $site->forceFill(['last_lead_at' => $receivedAt, 'quiet_notified_at' => null])->save();
        }

        if ($created && $lead->email !== null && $site->brand->autoReplyEnabled()) {
            SendAutoReply::dispatch((int) $lead->getKey());
        }

        return $lead;
    }
}
