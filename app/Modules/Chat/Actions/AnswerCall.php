<?php

declare(strict_types=1);

namespace App\Modules\Chat\Actions;

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Models\ChatCall;
use App\Modules\Chat\Support\AgentCard;
use App\Modules\Meta\Models\Conversation;
use RuntimeException;

/**
 * Somebody of the team takes a ringing call. Two steps, because the browser needs the caller's description before
 * it can make its own: `accept` claims the call (one statement, so of two people who click at once exactly one
 * gets it) and hands over the caller's description; `answer` stores this browser's and the two are connected.
 */
final class AnswerCall
{
    public function __construct(private readonly EndCall $end, private readonly ReplyToVisitor $replies) {}

    /**
     * @return string the caller's description
     *
     * @throws RuntimeException with words for the person when the call is gone or taken
     */
    public function accept(ChatCall $call, User $by): string
    {
        $call = $this->end->settle($call);

        $won = $call->status === ChatCall::RINGING && ChatCall::query()->withoutGlobalScopes()
            ->whereKey($call->getKey())->where('status', ChatCall::RINGING)
            ->update(['status' => ChatCall::CONNECTING, 'answered_by' => $by->getKey(), 'answered_at' => now(), 'agent_seen_at' => now()]) === 1;

        if (! $won) {
            $call->refresh();
            $other = $call->answered_by !== null && $call->answered_by !== $by->getKey() ? User::query()->find($call->answered_by) : null;

            throw new RuntimeException($other !== null ? $other->name.' answered this call.' : 'The caller hung up.');
        }

        return (string) $call->refresh()->offer;
    }

    /** @throws RuntimeException */
    public function answer(ChatCall $call, User $by, string $answer): ChatCall
    {
        $call = $this->end->settle($call);

        if ($call->status !== ChatCall::CONNECTING || $call->answered_by !== $by->getKey()) {
            throw new RuntimeException('This call is over.');
        }

        if (! StartCall::looksLikeSdp($answer)) {
            $this->end->handle($call, EndCall::BY_TEAM);

            throw new RuntimeException('The call could not connect.');
        }

        // Counted from now: the talking starts when both have what they need.
        $call->forceFill(['status' => ChatCall::ACTIVE, 'answer' => StartCall::sdp($answer), 'answered_at' => now(), 'agent_seen_at' => now()])->save();

        $conversation = Conversation::query()->withoutGlobalScopes()->find($call->conversation_id);

        if ($conversation !== null) {
            $this->replies->event($conversation, $by->name.' answered the call');
        }

        return $call;
    }

    /** The name the visitor sees for whoever answered (their chat name, as in the chat box's header). */
    public static function nameFor(ChatCall $call): ?string
    {
        $user = $call->answered_by !== null ? User::query()->find($call->answered_by) : null;

        return $user !== null ? AgentCard::of($user, withPhoto: false)['name'] : null;
    }
}
