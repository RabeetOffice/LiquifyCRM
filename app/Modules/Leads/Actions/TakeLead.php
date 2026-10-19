<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Leads\Exceptions\LeadAlreadyTaken;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * "I am on it": somebody of front sales takes a free lead for themselves.
 * The row is locked while it is read, so when two people click at the same
 * moment only the first gets it and the second is told who did. Assigning
 * goes through AssignLead, so the timeline, follow-ups and live updates are
 * the same as when a Manager assigns.
 */
final class TakeLead
{
    /** @throws LeadAlreadyTaken */
    public function handle(Lead $lead, User $user): Lead
    {
        return DB::transaction(function () use ($lead, $user): Lead {
            /** @var Lead|null $locked */
            $locked = Lead::query()->acrossBrands()->whereKey($lead->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->assigned_to !== null) {
                throw LeadAlreadyTaken::by($locked?->assignee?->name);
            }

            return app(AssignLead::class)->handle($locked, $user, $user);
        });
    }
}
