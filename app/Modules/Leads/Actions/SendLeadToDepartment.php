<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\LeadNotifier;

/**
 * The Admin hands a lead to a department; its Managers then assign an Agent,
 * or, when the department shares by round robin, the next Agent in turn
 * receives it straight away. An Agent from another department loses the
 * lead when it moves.
 */
final class SendLeadToDepartment
{
    public function handle(Lead $lead, Department $department, ?User $by = null): Lead
    {
        $previous = $lead->department_id;
        $keepAssignee = $lead->assigned_to !== null && $department->hasMember($lead->assigned_to);

        $lead->forceFill([
            'department_id' => $department->getKey(),
            'assigned_to' => $keepAssignee ? $lead->assigned_to : null,
            'assigned_at' => $keepAssignee ? $lead->assigned_at : null,
            'last_activity_at' => now(),
        ])->save();

        Audit::log(
            'leads.sent_to_department',
            $lead,
            ['from' => $previous, 'to' => $department->getKey()],
            "Sent to {$department->name}",
            department: $department,
            causer: $by,
            logName: 'leads',
        );

        // Round robin: the next Agent in turn gets it at once, so its Managers need not be told.
        $agent = $keepAssignee ? null : app(AutoAssignLead::class)->handle($lead, $by);

        if ($agent === null) {
            app(LeadNotifier::class)->sentToDepartment($lead, $department, $by);
        }

        return $lead;
    }
}
