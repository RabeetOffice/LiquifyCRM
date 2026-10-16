<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Take a lead out of the bin. It comes back as it was, with its notes,
 * follow-ups, chat and timeline (none of them were deleted). If its team
 * was removed in the meantime, or the person who had it no longer works in
 * the brand, it goes back to the queue instead of to nobody.
 */
final class RestoreLead
{
    public function handle(Lead $lead, User $by): Lead
    {
        return DB::transaction(function () use ($lead, $by): Lead {
            $returned = [];

            if ($lead->department_id !== null && ! Department::query()->forBrand($lead->brand_id)->whereKey($lead->department_id)->exists()) {
                $lead->department_id = null;
                $returned[] = 'team';
            }

            if ($lead->assigned_to !== null) {
                $owner = User::query()->find($lead->assigned_to);

                if ($owner === null || ! $owner->isActive() || ! $owner->isMemberOf($lead->brand_id)) {
                    $lead->assigned_to = null;
                    $lead->assigned_at = null;
                    $returned[] = 'owner';
                }
            }

            $lead->restore();

            Audit::log(
                'leads.restored',
                $lead,
                $returned === [] ? [] : ['returned_to_queue' => $returned],
                $returned === [] ? 'Lead restored from the bin' : 'Lead restored from the bin and returned to the queue',
                causer: $by,
                logName: 'leads',
            );

            DashboardCache::flush((int) $lead->brand_id);

            return $lead;
        });
    }
}
