<?php

declare(strict_types=1);

namespace App\Modules\Meta\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Support\ChatAccess;
use App\Modules\Chat\Support\LiveChat;
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
        // A visitor who has not given an email or phone number has no lead: their team decides (ChatAccess).
        if ($conversation->isLiveChat() && $conversation->lead_id === null) {
            return LiveChat::ready() && ChatAccess::canSeeVisitors($user, (int) $conversation->brand_id);
        }

        $lead = $this->lead($conversation);

        return $lead !== null && $user->can('view', $lead);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        // A website chat with no lead: Admins and the brand's Managers always; an Agent when nobody looks after it or it is theirs.
        if ($conversation->isLiveChat() && $conversation->lead_id === null) {
            if (! LiveChat::workReady() || ! ChatAccess::canSeeVisitors($user, (int) $conversation->brand_id)) {
                return false;
            }

            return $user->isGlobalAdmin() || $user->isManagerIn((int) $conversation->brand_id)
                || $conversation->chat_assigned_to === null || $conversation->chat_assigned_to === $user->getKey();
        }

        $lead = $this->lead($conversation);

        return $lead !== null && $user->can('update', $lead);
    }

    /** Close, reopen and leave notes: whoever may answer the chat. */
    public function work(User $user, Conversation $conversation): bool
    {
        return $conversation->isLiveChat() && $this->reply($user, $conversation);
    }

    /** Give a visitor's chat to somebody: Admins and the brand's Managers. */
    public function assignChat(User $user, Conversation $conversation): bool
    {
        return $conversation->isLiveChat() && $conversation->lead_id === null && LiveChat::workReady()
            && ($user->isGlobalAdmin() || $user->isManagerIn((int) $conversation->brand_id));
    }

    private function lead(Conversation $conversation): ?Lead
    {
        if ($conversation->lead_id === null) {
            return null;
        }

        return $conversation->relationLoaded('lead') ? $conversation->lead : Lead::query()->find($conversation->lead_id);
    }
}
