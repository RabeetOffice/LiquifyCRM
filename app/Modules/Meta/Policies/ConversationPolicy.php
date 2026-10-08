<?php

declare(strict_types=1);

namespace App\Modules\Meta\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;

/**
 * A chat follows its lead: whoever may see the lead may read the chat, and
 * whoever may work the lead may reply.
 */
final class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Conversation $conversation): bool
    {
        $lead = $this->lead($conversation);

        return $lead !== null && $user->can('view', $lead);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        $lead = $this->lead($conversation);

        return $lead !== null && $user->can('update', $lead);
    }

    private function lead(Conversation $conversation): ?Lead
    {
        if ($conversation->lead_id === null) {
            return null;
        }

        return $conversation->relationLoaded('lead') ? $conversation->lead : Lead::query()->find($conversation->lead_id);
    }
}
