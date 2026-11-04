<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use App\Modules\Activity\Models\Activity;
use App\Modules\Brands\Models\Brand;
use App\Modules\Chat\Models\ChatAttachment;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Meta\Models\Conversation;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Removing a website chat for good: its messages, its files (rows now, the files on the disk once nothing points at
 * them), the bell entries and log lines that name the visitor. Used when a lead is deleted forever and when a visitor
 * who never gave an email address or phone number is past the days the privacy page promises.
 */
final class ChatErasure
{
    /**
     * Delete the chat. The caller removes the returned files from the disk after its own transaction.
     *
     * @return list<array{0: string, 1: string}> disk and path of each file
     */
    public static function erase(Conversation $conversation): array
    {
        $paths = [];

        if (LiveChat::extrasReady()) {
            $files = ChatAttachment::query()->whereHas('message', fn ($message) => $message->where('conversation_id', $conversation->getKey()))->get();

            foreach ($files as $file) {
                $paths[] = [$file->disk, $file->path];
            }
        }

        // Bell entries that open this chat carry the visitor's name.
        $slug = Brand::withTrashed()->whereKey($conversation->brand_id)->value('slug');

        if (is_string($slug)) {
            DatabaseNotification::query()->where(fn ($query) => $query
                ->where('data->url', 'like', '%/b/'.$slug.'/inbox?chat='.$conversation->getKey())
                ->orWhere('data->url', 'like', '%/b/'.$slug.'/live-chat/chats?chat='.$conversation->getKey()))->delete();
        }

        // What the chat's own log lines say names the visitor too (closed by, given to).
        Activity::query()->where('subject_type', $conversation->getMorphClass())->where('subject_id', $conversation->getKey())->delete();

        $conversation->delete();

        return $paths;
    }

    /** The visitors who are a lead's: their email, phone number and address go with it. */
    public static function eraseVisitorsOf(int $leadId): void
    {
        if (LiveChat::ready()) {
            $visitorIds = ChatVisitor::query()->withoutGlobalScopes()->where('lead_id', $leadId)->pluck('id');

            // The browser the visitor chatted from, with the pages it read (they go with the session).
            if (LiveChat::trackingReady()) {
                ChatSession::query()->withoutGlobalScopes()->whereIn('chat_visitor_id', $visitorIds)->delete();
            }

            ChatVisitor::query()->withoutGlobalScopes()->whereIn('id', $visitorIds)->delete();
        }
    }
}
