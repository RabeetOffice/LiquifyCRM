<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Support\ChatAccess;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the open LiquifyCRM page shows and rings for, on whatever screen it is (user request, 13 Nov 2026: chats were
 * missed): the website chats in which a visitor is waiting for this person's answer, and the visitors who have just
 * arrived on a website this person works. The page draws a card for each and keeps ringing for a waiting chat until
 * somebody opens it.
 */
final class TeamChatController
{
    /** A chat whose visitor wrote longer ago than this is no longer rung for (it stays in Chats with its count). */
    public const WAITING_MINUTES = 30;

    /** A visit that began within this many seconds is "just arrived". */
    public const ARRIVED_SECONDS = 90;

    public function waiting(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! LiveChat::workReady()) {
            return response()->json(['chats' => [], 'visitors' => []]);
        }

        $chats = Conversation::query()->acrossBrands()
            ->where('channel', Conversation::CHANNEL_LIVECHAT)
            ->where('unread_count', '>', 0)
            ->where('last_inbound_at', '>=', now()->subMinutes(self::WAITING_MINUTES))
            ->latest('last_inbound_at')
            ->limit(20)
            ->get()
            ->filter(fn (Conversation $chat): bool => ! $chat->isClosed() && $this->ringsFor($chat, $user))
            ->values();

        $visitors = $this->arrived($user);
        $brands = Brand::query()->whereIn('id', $chats->pluck('brand_id')->merge($visitors->pluck('brand_id'))->unique())->get()->keyBy('id');

        return response()->json([
            'chats' => $chats->map(fn (Conversation $chat): array => [
                'id' => (int) $chat->getKey(),
                'at' => (int) $chat->last_inbound_at?->getTimestamp(),
                'name' => $chat->displayName(),
                'text' => Str::limit((string) $chat->last_message_preview, 120),
                'count' => (int) $chat->unread_count,
                'brand' => $brands->get($chat->brand_id)?->name,
                'url' => $brands->get($chat->brand_id)?->url('live-chat/chats?chat='.$chat->getKey()),
            ])->filter(fn (array $chat): bool => $chat['url'] !== null)->values()->all(),
            'visitors' => $visitors->map(fn (ChatSession $session): array => [
                'id' => (int) $session->getKey(),
                'at' => (int) $session->visit_started_at?->getTimestamp(),
                'name' => $session->displayName(),
                'page' => $session->path(),
                'from' => LiveChat::sourceReady() ? $session->cameFrom() : '',
                'place' => trim(implode(', ', array_filter([(string) $session->getAttribute('city'), strtoupper((string) $session->country)]))),
                'brand' => $brands->get($session->brand_id)?->name,
                'url' => $brands->get($session->brand_id)?->url('live-chat/visitors'),
            ])->filter(fn (array $visitor): bool => $visitor['url'] !== null)->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The person who looks after the chat (its own, or its lead's); with nobody on it, everybody who may answer it:
     * Admins, the brand's Managers and the Agents who see visitors.
     */
    private function ringsFor(Conversation $chat, User $user): bool
    {
        $owner = $chat->lead_id !== null ? Lead::query()->acrossBrands()->whereKey($chat->lead_id)->value('assigned_to') : $chat->chat_assigned_to;

        return $owner !== null ? (int) $owner === (int) $user->getKey() : $user->can('reply', $chat);
    }

    /**
     * Browsers whose visit to a website began a moment ago, in the brands whose visitors this person works.
     *
     * @return Collection<int, ChatSession>
     */
    private function arrived(User $user): Collection
    {
        if (! LiveChat::trackingReady()) {
            return new Collection;
        }

        $sessions = ChatSession::query()->acrossBrands()
            ->where('visit_started_at', '>=', now()->subSeconds(self::ARRIVED_SECONDS))
            ->where('last_seen_at', '>=', now()->subSeconds(ChatSession::ONLINE_SECONDS))
            ->with('visitor')
            ->latest('visit_started_at')
            ->limit(10)
            ->get();

        $allowed = [];

        return $sessions->filter(function (ChatSession $session) use ($user, &$allowed): bool {
            $brand = (int) $session->brand_id;

            return $allowed[$brand] ??= ChatAccess::canSeeVisitors($user, $brand);
        })->values();
    }
}
