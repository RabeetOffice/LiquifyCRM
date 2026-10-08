<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Leads\Models\Lead;

/** Result of CreateLead: the lead, and whether it matched an existing one. */
final class LeadCreation
{
    public function __construct(public readonly Lead $lead, public readonly bool $wasMerged) {}
}
