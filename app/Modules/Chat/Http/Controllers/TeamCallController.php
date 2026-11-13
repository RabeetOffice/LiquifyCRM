<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Actions\AnswerCall;
use App\Modules\Chat\Actions\EndCall;
use App\Modules\Chat\Models\ChatCall;
use App\Modules\Chat\Support\CallServers;
use App\Modules\Chat\Support\LiveChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The team's side of a voice call from a website chat box (docs/CALLS_PLAN.md): which calls are ringing for this
 * person, taking one, and the call while it lasts. Every address asks the chat's policy (`answerCall`); once a call
 * is taken only the person who took it can reach it.
 */
final class TeamCallController
{
    /** The calls ringing right now that this person may answer (asked by the open page when something changed). */
    public function incoming(Request $request, EndCall $end): JsonResponse
    {
        $user = $this->user($request);

        if (! LiveChat::callsReady()) {
            return response()->json(['calls' => []]);
        }

        $calls = ChatCall::query()->withoutGlobalScopes()->where('status', ChatCall::RINGING)->with('conversation')->oldest('id')->limit(20)->get()
            // A call nobody ended (a closed tab) is settled here, so its "missed call" line is written.
            ->map(fn (ChatCall $call): ChatCall => $end->settle($call))
            ->filter(fn (ChatCall $call): bool => $call->status === ChatCall::RINGING && $call->conversation !== null && $user->can('answerCall', $call->conversation))
            ->values();

        $brands = Brand::query()->whereIn('id', $calls->pluck('brand_id')->unique())->get()->keyBy('id');

        return response()->json(['calls' => $calls->map(fn (ChatCall $call): array => [
            'id' => (int) $call->getKey(),
            'name' => $call->conversation->displayName(),
            'brand' => $brands->get($call->brand_id)?->name,
            'url' => $brands->get($call->brand_id)?->url('live-chat/chats?chat='.$call->conversation_id),
        ])->all()])->header('Cache-Control', 'no-store');
    }

    /** Take the call: the first person wins. The answer holds what this browser needs to make its own description. */
    public function accept(Request $request, string $call, AnswerCall $answer): JsonResponse
    {
        $found = $this->call($request, $call, mine: false);

        try {
            $offer = $answer->accept($found, $this->user($request));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['offer' => $offer, 'iceServers' => CallServers::list(), 'name' => $found->conversation?->displayName()])->header('Cache-Control', 'no-store');
    }

    public function answer(Request $request, string $call, AnswerCall $answer): JsonResponse
    {
        $found = $this->call($request, $call, mine: true);

        try {
            $answer->answer($found, $this->user($request), (string) $request->input('sdp'));
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 409);
        }

        return response()->json(['status' => ChatCall::ACTIVE]);
    }

    /** Every few seconds while the call lasts: is the visitor still there? (And: this side is.) */
    public function show(Request $request, string $call, EndCall $end): JsonResponse
    {
        $found = $end->settle($this->call($request, $call, mine: true));

        if ($found->isLive()) {
            $found->forceFill(['agent_seen_at' => now()])->saveQuietly();
        }

        return response()->json(['status' => $found->status, 'seconds' => $found->seconds()])->header('Cache-Control', 'no-store');
    }

    public function end(Request $request, string $call, EndCall $end): JsonResponse
    {
        $ended = $end->handle($this->call($request, $call, mine: true), EndCall::BY_TEAM);

        return response()->json(['status' => $ended->status, 'seconds' => $ended->seconds()]);
    }

    /** The call, for somebody who may answer its chat; `mine`: only for the person who took it. */
    private function call(Request $request, string $id, bool $mine): ChatCall
    {
        abort_unless(LiveChat::callsReady(), 404);

        $user = $this->user($request);
        $call = ChatCall::query()->withoutGlobalScopes()->with('conversation')->find((int) $id);

        // A stranger learns nothing, not even that the call exists.
        abort_if($call === null || $call->conversation === null || ! $user->can('answerCall', $call->conversation), 404);
        abort_if($mine && $call->answered_by !== $user->getKey(), 403);

        return $call;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
