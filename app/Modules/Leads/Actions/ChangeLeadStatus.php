<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Events\LeadStatusChanged;
use App\Modules\Leads\Exceptions\LeadIsWon;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;

/**
 * Move a lead along the brand's pipeline. The first move away from New
 * stamps first_contacted_at, which feeds the speed-to-contact numbers.
 *
 * A lead that is Won with a recorded sale stays in Won (user request,
 * 19 Oct 2026): moving it away and winning it again asked for the amount
 * again and counted the sale twice. A mistake is undone by correcting or
 * removing the sale (CorrectSale), which brings the lead back by itself.
 */
final class ChangeLeadStatus
{
    /** @throws LeadIsWon */
    public function handle(Lead $lead, LeadStatus $status, ?User $by = null): Lead
    {
        if ((int) $status->brand_id !== (int) $lead->brand_id) {
            throw new \InvalidArgumentException('That status belongs to another brand.');
        }

        $previous = $lead->status;

        if ($previous->getKey() === $status->getKey()) {
            return $lead;
        }

        if ($previous->is_won && ! $status->is_won && $lead->hasSale()) {
            throw LeadIsWon::withSale();
        }

        $lead->forceFill([
            'status_id' => $status->getKey(),
            'first_contacted_at' => $lead->first_contacted_at ?? ($previous->is_default && ! $status->is_default ? now() : null),
            'last_activity_at' => now(),
        ])->save();

        $lead->setRelation('status', $status);

        Audit::log(
            'leads.status_changed',
            $lead,
            ['from' => $previous->name, 'to' => $status->name, 'from_id' => $previous->getKey(), 'to_id' => $status->getKey()],
            "{$previous->name} → {$status->name}",
            causer: $by,
            logName: 'leads',
        );

        LeadStatusChanged::dispatch($lead);

        return $lead;
    }
}
