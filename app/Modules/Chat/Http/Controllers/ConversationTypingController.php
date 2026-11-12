<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Support\ChatTyping;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Http\JsonResponse;

/**
 * The team member answering a website chat is typing: the visitor's chat box shows the dots for a few seconds.
 * Called by the Inbox every few seconds while they type; only whoever may answer that chat is heard.
 */
final class ConversationTypingController
{
    public function __invoke(Brand $brand, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->isLiveChat() && (bool) auth()->user()?->can('reply', $conversation), 403);

        request()->boolean('stop')
            ? ChatTyping::agentStops((int) $conversation->getKey())
            : ChatTyping::agentTypes((int) $conversation->getKey());

        return response()->json(['ok' => true]);
    }
}
