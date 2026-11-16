<?php

declare(strict_types=1);

namespace App\Modules\Leads\Jobs;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadInsights;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The assistant's background work on one lead (user request, 16 Nov 2026): rate a new enquiry hot, warm or cold, or
 * read why a lost lead was lost. Queued, so nobody waits for it and a lead is never held up by it; one try, and a
 * failure only means the lead has no tag (the assistant being away is not an error).
 */
final class AssistLead implements ShouldQueue
{
    use Queueable;

    public const RATE = 'rate';

    public const WHY_LOST = 'why-lost';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $leadId, public readonly string $task) {}

    /** A new lead: how ready to buy does it sound? */
    public static function rate(Lead $lead): void
    {
        if (LeadInsights::assisted()) {
            self::dispatch((int) $lead->getKey(), self::RATE)->afterCommit();
        }
    }

    /** A lead just marked lost: why? */
    public static function whyLost(Lead $lead): void
    {
        if (LeadInsights::assisted()) {
            self::dispatch((int) $lead->getKey(), self::WHY_LOST)->afterCommit();
        }
    }

    public function handle(CurrentBrand $current): void
    {
        $lead = Lead::query()->acrossBrands()->find($this->leadId);

        if ($lead === null || ! LeadInsights::assisted()) {
            return;
        }

        // Inside the lead's brand, so its notes, follow-ups and chat are the ones read.
        $current->within(Brand::query()->find($lead->brand_id), function () use ($lead): void {
            $this->task === self::RATE ? LeadInsights::rate($lead) : LeadInsights::whyLost($lead);
        });
    }
}
