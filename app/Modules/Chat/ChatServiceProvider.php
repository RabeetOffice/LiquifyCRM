<?php

declare(strict_types=1);

namespace App\Modules\Chat;

use App\Modules\Chat\Console\PruneChatCommand;
use App\Modules\Chat\Models\CannedReply;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Policies\CannedReplyPolicy;
use App\Modules\Chat\Policies\ChatWidgetPolicy;
use App\Modules\Chat\Support\AgentPresence;
use App\Modules\Chat\Support\Realtime;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Support\Live;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Live chat on the brands' websites (user request, 3 Nov 2026; plan in docs/LIVECHAT_PLAN.md). A visitor's
 * chat is a `Conversation` of the Inbox with `channel = livechat`; this module owns the widget, the visitor
 * and the settings screen.
 */
final class ChatServiceProvider extends ModuleServiceProvider
{
    protected string $module = 'Chat';

    protected array $commands = [
        PruneChatCommand::class,
    ];

    protected array $policies = [
        ChatWidget::class => ChatWidgetPolicy::class,
        CannedReply::class => CannedReplyPolicy::class,
    ];

    protected function bootModule(): void
    {
        // The brand's chats changed: one tiny signal to the open pages (Realtime; nothing happens without an Ably key).
        Live::whenChatChanged(fn (int $brandId) => Realtime::notifyBrand($brandId));

        // A page of the brand is open in somebody's browser: somebody is here to answer (AgentPresence).
        Live::whenSeen(function (int $userId, ?int $brandId): void {
            if ($brandId !== null) {
                AgentPresence::seen($userId, $brandId);
            }
        });

        // The launcher script and the chat box: no session, no cookie (routes/public.php).
        Route::middleware([])->group($this->path().'/routes/public.php');

        // A visitor is told apart by the token they carry, an address (an office, a school) by its IP: the limits are
        // generous for people and short for a script. A page view asks `config` once; polling asks `messages`.
        RateLimiter::for('chat-config', fn (Request $request): Limit => Limit::perMinute(300)->by('chat-config:'.$request->ip()));
        RateLimiter::for('chat-init', fn (Request $request): Limit => Limit::perMinute(60)->by('chat-init:'.$request->ip()));
        // A script can get a new token from `init` whenever it likes, so a limit per token alone stops nothing: every one also
        // has a ceiling per address, high enough for a busy office of real visitors (hardening, 12 Nov 2026).
        $both = fn (string $name, int $perToken, int $perAddress): \Closure => fn (Request $request): array => [
            Limit::perMinute($perToken)->by($name.':t:'.($request->header('X-Chat-Token') ?: $request->ip())),
            Limit::perMinute($perAddress)->by($name.':ip:'.$request->ip()),
        ];
        RateLimiter::for('chat-poll', $both('chat-poll', 60, 1200));
        RateLimiter::for('chat-send', $both('chat-send', 20, 60));
        RateLimiter::for('chat-upload', $both('chat-upload', 6, 20));
        RateLimiter::for('chat-typing', $both('chat-typing', 30, 300));
        RateLimiter::for('chat-details', $both('chat-details', 6, 15));
        // The launcher pings every 30 seconds and on a page change: a browser (its "vid") that asks more often is a script.
        RateLimiter::for('chat-ping', function (Request $request): array {
            $data = json_decode(mb_substr((string) $request->getContent(), 0, 4096), true);
            $vid = is_array($data) && is_string($data['vid'] ?? null) ? mb_substr($data['vid'], 0, 40) : '';

            return [
                Limit::perMinute(600)->by('chat-ping:ip:'.$request->ip()),
                Limit::perMinute(20)->by('chat-ping:vid:'.$request->ip().'|'.$vid),
            ];
        });
    }
}
