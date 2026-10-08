<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Events\LeadAssigned;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadNotifier;
use InvalidArgumentException;

/**
 * Give a lead to an Agent (or take it back with null). The lead inherits the
 * Agent's department when it has none, so department counts stay right.
 */
final class AssignLead
{
    public function handle(Lead $lead, ?User $agent, ?User $by = null): Lead
    {
        if ($agent !== null && ! $agent->isMemberOf($lead->brand_id)) {
            throw new InvalidArgumentException('That person is not a member of this brand.');
        }

        $previous = $lead->assigned_to;

        if ($agent !== null && $lead->department_id === null) {
            $lead->department_id = $agent->departmentIds($lead->brand_id)[0] ?? null;
        }

        $lead->forceFill([
            'assigned_to' => $agent?->getKey(),
            'assigned_at' => $agent ? now() : null,
            'last_activity_at' => now(),
        ])->save();

        Audit::log(
            $agent ? 'leads.assigned' : 'leads.unassigned',
            $lead,
            ['from' => $previous, 'to' => $agent?->getKey(), 'department_id' => $lead->department_id],
            $agent ? "Assigned to {$agent->name}" : 'Returned to the queue',
            causer: $by,
            logName: 'leads',
        );

        LeadAssigned::dispatch($lead);

        if ($agent !== null && $agent->getKey() !== $by?->getKey()) {
            app(LeadNotifier::class)->assigned($lead, $agent, $by);
        }

        return $lead;
    }
}
