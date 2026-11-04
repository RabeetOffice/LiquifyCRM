<?php

declare(strict_types=1);

namespace App\Modules\Chat\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Live chat needs its own tables and a few columns on the chat tables. The code reaches the server before an
 * Admin clicks "Update system", so the menu item, the screen and the Inbox ask here first and behave as
 * before until they exist.
 */
final class LiveChat
{
    private static bool $ready = false;

    private static bool $workReady = false;

    private static bool $extrasReady = false;

    public static function ready(): bool
    {
        // The last column of the update: when it is there, the rest is too.
        return self::$ready = self::$ready || (Schema::hasTable('chat_widgets') && Schema::hasColumn('messages', 'sender_type'));
    }

    /** Files in a chat and saved replies: the tables of the third update are there. */
    public static function extrasReady(): bool
    {
        return self::$extrasReady = self::$extrasReady || (self::workReady() && Schema::hasTable('canned_replies') && Schema::hasTable('chat_attachments'));
    }

    /** The team can work a website chat (answer, assign, close): the columns of the second update are there. */
    public static function workReady(): bool
    {
        return self::$workReady = self::$workReady || (self::ready() && Schema::hasColumn('conversations', 'first_response_at'));
    }
}
