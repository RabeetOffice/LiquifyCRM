<?php

declare(strict_types=1);

namespace App\Modules\Leads\Events;

use App\Modules\Leads\Models\Lead;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired by the MVP so later automations can react without touching lead code. */
final class LeadStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly Lead $lead) {}
}
