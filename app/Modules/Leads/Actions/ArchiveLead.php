<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Leads\Models\Lead;

/**
 * A Manager puts a lead that leads nowhere (spam, a wrong number, somebody
 * who never answers) out of the working lists: the list, the queue, the
 * pipeline, the counts and the follow-ups. Nothing is deleted and it keeps
 * its owner, notes, chat and history; it comes back with one click, or by
 * itself when the person gets in touch again (Lead::heardFrom).
 */
final class ArchiveLead
{
    public function handle(Lead $lead, User $by): Lead
    {
        if ($lead->isArchived()) {
            return $lead;
        }

        $lead->forceFill(['archived_at' => now(), 'archived_by' => $by->getKey()])->save();

        Audit::log('leads.archived', $lead, [], 'Archived', causer: $by, logName: 'leads');
        DashboardCache::flush((int) $lead->brand_id);

        return $lead;
    }

    public function bringBack(Lead $lead, ?User $by = null, string $why = 'Brought back from the archive'): Lead
    {
        if (! $lead->isArchived()) {
            return $lead;
        }

        $lead->forceFill(['archived_at' => null, 'archived_by' => null, 'last_activity_at' => now()])->save();

        Audit::log('leads.unarchived', $lead, [], $why, causer: $by, logName: 'leads');
        DashboardCache::flush((int) $lead->brand_id);

        return $lead;
    }
}
