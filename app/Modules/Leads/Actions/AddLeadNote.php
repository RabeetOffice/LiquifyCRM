<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadNote;

final class AddLeadNote
{
    public function handle(Lead $lead, string $body, ?User $by = null, bool $pinned = false): LeadNote
    {
        $note = $lead->notes()->create([
            'brand_id' => $lead->brand_id,
            'user_id' => $by?->getKey(),
            'body' => trim($body),
            'is_pinned' => $pinned,
        ]);

        $lead->touchActivity();

        Audit::log(
            'leads.note_added',
            $lead,
            ['note_id' => $note->getKey(), 'excerpt' => str($note->body)->limit(140)->toString()],
            'Note added',
            causer: $by,
            logName: 'leads',
        );

        return $note;
    }
}
