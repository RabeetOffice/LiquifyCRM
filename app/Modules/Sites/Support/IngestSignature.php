<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

/**
 * The signature liquify.php puts on every lead: HMAC-SHA256 of
 * "timestamp.body" with the site secret, sent as "sha256=<hex>".
 */
final class IngestSignature
{
    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function matches(string $secret, string $timestamp, string $body, string $signature): bool
    {
        return hash_equals(self::sign($secret, $timestamp, $body), trim($signature));
    }

    /** A copied request cannot be replayed once it is older than the window (5 minutes). */
    public static function isFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= (int) config('liquify.ingest.replay_window', 300);
    }
}
