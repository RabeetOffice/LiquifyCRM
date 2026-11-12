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

    private static bool $trackingReady = false;

    private static bool $geoReady = false;

    private static bool $ratingReady = false;

    private static bool $proxyReady = false;

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

    /** Who is on the website now and the pages they read: the tables of the fourth update are there. */
    public static function trackingReady(): bool
    {
        return self::$trackingReady = self::$trackingReady || (self::workReady() && Schema::hasTable('chat_sessions') && Schema::hasTable('chat_page_views'));
    }

    /** The team can work a website chat (answer, assign, close): the columns of the second update are there. */
    /** Where visitors are (region, network, time zone, VPN) on visitors and browsers: 1.52.0, after "Update system". */
    public static function geoReady(): bool
    {
        return self::$geoReady = self::$geoReady || (self::trackingReady() && Schema::hasColumn('chat_sessions', 'ip'));
    }

    /** Thumbs up or down on a chat (conversations.rating): 1.52.0, after "Update system". */
    public static function ratingReady(): bool
    {
        return self::$ratingReady = self::$ratingReady || (self::workReady() && Schema::hasColumn('conversations', 'rating'));
    }

    /** The chat served through liquify-chat.php on the brand's website (chat_widgets.proxy_secret): 1.52.0. */
    public static function proxyReady(): bool
    {
        return self::$proxyReady = self::$proxyReady || (self::ready() && Schema::hasColumn('chat_widgets', 'proxy_secret'));
    }

    public static function workReady(): bool
    {
        return self::$workReady = self::$workReady || (self::ready() && Schema::hasColumn('conversations', 'first_response_at'));
    }
}
