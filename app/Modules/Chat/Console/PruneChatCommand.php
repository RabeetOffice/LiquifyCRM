<?php

declare(strict_types=1);

namespace App\Modules\Chat\Console;

use App\Modules\Chat\Models\ChatAttachment;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Support\ChatErasure;
use App\Modules\Chat\Support\ChatFiles;
use App\Modules\Chat\Support\LiveChat;
use App\Modules\Core\Support\Audit;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The nightly clean-up of live chat, as the privacy page promises: files are deleted after `file_days`; the chat of a
 * visitor who never gave an email address or a phone number goes after `anonymous_days`, and so do visitors who only
 * looked. A chat of somebody who became a lead stays as long as the lead does.
 */
final class PruneChatCommand extends Command
{
    protected $signature = 'chat:prune';

    protected $description = 'Delete old files of website chats and the chats of visitors who never gave their details';

    public function handle(): int
    {
        if (! LiveChat::extrasReady()) {
            return self::SUCCESS;
        }

        $files = 0;
        $chats = 0;
        $fileCutoff = now()->subDays(max(1, (int) config('liquify.chat.file_days', 180)));
        $visitorCutoff = now()->subDays(max(1, (int) config('liquify.chat.anonymous_days', 14)));

        // 1. Files past their days, in every chat.
        ChatAttachment::query()->where('created_at', '<', $fileCutoff)->chunkById(200, function ($old) use (&$files): void {
            $files += ChatFiles::forget($old);
        });

        // 2. Chats of visitors who never gave details: the messages, the files, then the visitor.
        Conversation::query()->withoutGlobalScopes()
            ->where('channel', Conversation::CHANNEL_LIVECHAT)
            ->whereNull('lead_id')
            ->where(fn ($query) => $query->where('last_message_at', '<', $visitorCutoff)->orWhere(fn ($quiet) => $quiet->whereNull('last_message_at')->where('created_at', '<', $visitorCutoff)))
            ->chunkById(100, function ($old) use (&$chats): void {
                foreach ($old as $conversation) {
                    $visitorId = $conversation->chat_visitor_id;

                    foreach (ChatErasure::erase($conversation) as [$disk, $path]) {
                        Storage::disk($disk)->delete($path);
                    }

                    // The visitor goes with the chat, unless it is a lead's or has another chat.
                    if ($visitorId !== null) {
                        ChatVisitor::query()->withoutGlobalScopes()->whereKey($visitorId)->whereNull('lead_id')->whereDoesntHave('conversations')->delete();
                    }

                    $chats++;
                }
            });

        // 3. Visitors with no lead who have not been seen for those days (they only looked, or their chat is gone now).
        $visitors = ChatVisitor::query()->withoutGlobalScopes()
            ->whereNull('lead_id')
            ->where('last_seen_at', '<', $visitorCutoff)
            ->whereDoesntHave('conversations')
            ->delete();

        // 4. Who was on the website, and the pages they read, past their days.
        $sessions = LiveChat::trackingReady()
            ? ChatSession::query()->withoutGlobalScopes()->where('last_seen_at', '<', now()->subDays(max(1, (int) config('liquify.chat.page_view_days', 30))))->delete()
            : 0;

        if ($files + $chats + $visitors + $sessions > 0) {
            Audit::log('chat.pruned', null, ['files' => $files, 'chats' => $chats, 'visitors' => $visitors, 'sessions' => $sessions], 'Live chat clean-up: '.$files.' file(s), '.$chats.' chat(s), '.$visitors.' visitor(s) and '.$sessions.' website visit(s) deleted', logName: 'chat');
        }

        $this->info("Deleted {$files} file(s), {$chats} chat(s) and {$visitors} visitor(s).");

        return self::SUCCESS;
    }
}
