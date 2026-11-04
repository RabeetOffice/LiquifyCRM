<?php

declare(strict_types=1);

namespace App\Modules\Leads\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\LeadNote;

/**
 * Corrects a note (user request, 4 Nov 2026). The note keeps its author and
 * its date and says who edited it; the log entry keeps what it said before,
 * so an Admin can still see the first words.
 */
final class EditLeadNote
{
    public function handle(LeadNote $note, string $body, ?User $by = null): LeadNote
    {
        $body = trim($body);
        $before = $note->body;

        if ($body === $before) {
            return $note;
        }

        $note->forceFill([
            'body' => $body,
            'edited_at' => now(),
            'edited_by' => $by?->getKey(),
        ])->save();

        $lead = $note->lead;

        if ($lead !== null) {
            Audit::log(
                'leads.note_edited',
                $lead,
                [
                    'note_id' => $note->getKey(),
                    'excerpt' => str($body)->limit(140)->toString(),
                    'before' => str($before)->limit(140)->toString(),
                ],
                'Note edited',
                causer: $by,
                logName: 'leads',
            );
        }

        return $note;
    }
}
