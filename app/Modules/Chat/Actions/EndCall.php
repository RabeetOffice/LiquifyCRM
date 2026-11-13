<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Chat\Models\ChatCall;
use App\Modules\Chat\Support\ChatNotifier;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * A voice call is over: somebody hung up, nobody answered, or a side went quiet. The call's set-up messages (they
 * hold IP addresses) are emptied here, the chat gets a line for the team, and a call nobody answered goes to the bell.
 */
final class EndCall
{
    public const BY_VISITOR = 'visitor';

    public const BY_TEAM = 'team';

    public const BY_TIMEOUT = 'timeout';

    public function __construct(private readonly ReplyToVisitor $replies, private readonly ChatNotifier $notifier) {}

    public function handle(ChatCall $call, string $by): ChatCall
    {
        // Two endings at once (both hang up, a timeout and a click): the first one writes the line.
        $ended = DB::transaction(function () use ($call, $by): ?ChatCall {
            $fresh = ChatCall::query()->withoutGlobalScopes()->lockForUpdate()->find($call->getKey());

            if ($fresh === null || ! $fresh->isLive()) {
                return null;
            }

            $fresh->forceFill([
                'status' => match ($fresh->status) {
                    ChatCall::ACTIVE => ChatCall::ENDED,
                    ChatCall::CONNECTING => ChatCall::FAILED,
                    default => ChatCall::MISSED,
                },
                'ended_at' => now(),
                'ended_by' => $by,
                'offer' => null,
                'answer' => null,
            ])->save();

            return $fresh;
        });

        if ($ended === null) {
            return $call->refresh();
        }

        $conversation = Conversation::query()->withoutGlobalScopes()->find($ended->conversation_id);

        if ($conversation !== null) {
            $this->replies->event($conversation, match ($ended->status) {
                ChatCall::ENDED => 'Voice call ended · '.ChatCall::length($ended->seconds()),
                ChatCall::FAILED => 'The voice call could not connect',
                default => $by === self::BY_VISITOR ? 'Missed call: the visitor hung up before anybody answered' : 'Missed call: nobody answered',
            });

            if ($ended->status === ChatCall::MISSED) {
                $this->notifier->missedCall($conversation);
            }
        }

        return $ended;
    }

    /**
     * What time did to a call nobody ended: it rang too long, the answer never came, or one side has not been heard
     * from (a closed tab). Worked out whenever a call is read, so nothing waits for the scheduler.
     */
    public function settle(ChatCall $call): ChatCall
    {
        if (! $call->isLive()) {
            return $call;
        }

        $silentSince = now()->subSeconds(ChatCall::SILENT_SECONDS);

        $over = match ($call->status) {
            ChatCall::RINGING => $call->rang_at === null || $call->rang_at->lt(now()->subSeconds(ChatCall::RING_SECONDS))
                || ($call->visitor_seen_at !== null && $call->visitor_seen_at->lt($silentSince)),
            ChatCall::CONNECTING => $call->answered_at === null || $call->answered_at->lt(now()->subSeconds(ChatCall::CONNECT_SECONDS)),
            default => ($call->visitor_seen_at !== null && $call->visitor_seen_at->lt($silentSince))
                || ($call->agent_seen_at !== null && $call->agent_seen_at->lt($silentSince)),
        };

        return $over ? $this->handle($call, self::BY_TIMEOUT) : $call;
    }
}
