<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Live updates without websockets (the hosting runs no daemons). Every brand
 * and every person has a stamp that moves whenever something of theirs
 * changes. An open screen asks for the stamps every few seconds with one
 * small request (GET /live) and only reloads its data when a stamp moved,
 * so a change shows up within seconds and a quiet screen costs almost nothing.
 */
final class Live
{
    /** One process writes a brand's stamp at most this often; later changes are flushed when the request or job ends. */
    private const QUIET_SECONDS = 1.0;

    /** @var array<string, float> cache key => when this process last wrote it */
    private static array $written = [];

    /** @var array<string, true> keys changed again inside the quiet time */
    private static array $waiting = [];

    /** Something of this brand changed: a lead, a note, a task, a sale, a chat, a member. */
    public static function touch(?int $brandId): void
    {
        if ($brandId !== null) {
            self::mark('live:brand:'.$brandId);
            self::mark('live:all');
        }
    }

    /**
     * A chat of this brand changed: a message came in or went out, Facebook
     * confirmed it was delivered or read, a chat's unread count moved. Only the
     * screens that show chats reload for it (the Inbox, a chat, a lead's
     * Messenger tab, the menu's counts), not every screen of the brand: a busy
     * Page sends several of these a second (20 Oct 2026).
     */
    public static function touchChat(?int $brandId): void
    {
        if ($brandId !== null) {
            self::mark('live:chat:'.$brandId);
            // The admin area's Overview counts the chats waiting for a reply in every brand.
            self::mark('live:chat:all');
        }
    }

    /** Something for this person arrived: a notification. */
    public static function touchUser(int $userId): void
    {
        self::mark('live:user:'.$userId);
    }

    /**
     * @return array{b: int, c: int, u: int} the stamp of the brand (or of all brands), of its chats and of the person
     */
    public static function stamps(?int $brandId, int $userId, bool $everyBrand = false): array
    {
        $brandKey = $brandId !== null ? 'live:brand:'.$brandId : ($everyBrand ? 'live:all' : null);
        $chatKey = $brandId !== null ? 'live:chat:'.$brandId : ($everyBrand ? 'live:chat:all' : null);
        $userKey = 'live:user:'.$userId;
        $found = Cache::many(array_values(array_filter([$brandKey, $chatKey, $userKey])));

        return [
            'b' => $brandKey !== null ? (int) ($found[$brandKey] ?? 0) : 0,
            'c' => $chatKey !== null ? (int) ($found[$chatKey] ?? 0) : 0,
            'u' => (int) ($found[$userKey] ?? 0),
        ];
    }

    /**
     * What an open page shows to GET /api/live: who it is and which brand it
     * looks at, signed, so that request needs no session and no sign-in query.
     * A stamp is only a number, and the token stops working after 12 hours.
     */
    public static function token(int $userId, ?int $brandId, bool $everyBrand = false): string
    {
        $scope = $brandId !== null ? (string) $brandId : ($everyBrand ? 'all' : 'none');
        $payload = $userId.'.'.$scope.'.'.(now()->getTimestamp() + 43200);

        return $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /**
     * @return array{b: int, c: int, u: int}|null null for a token that is forged, damaged or too old
     */
    public static function read(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 4) {
            return null;
        }

        [$userId, $scope, $expires, $signature] = $parts;
        $payload = $userId.'.'.$scope.'.'.$expires;

        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $signature) || (int) $expires < now()->getTimestamp() || ! ctype_digit($userId)) {
            return null;
        }

        return self::stamps(ctype_digit($scope) ? (int) $scope : null, (int) $userId, $scope === 'all');
    }

    /** Called when a request or a queued job ends: write the stamps that changed inside the quiet time. */
    public static function flush(): void
    {
        foreach (array_keys(self::$waiting) as $key) {
            self::write($key);
        }
    }

    /** Between tests and between jobs nothing is remembered. */
    public static function forget(): void
    {
        self::$written = [];
        self::$waiting = [];
    }

    private static function mark(string $key): void
    {
        if (microtime(true) - (self::$written[$key] ?? 0.0) < self::QUIET_SECONDS) {
            self::$waiting[$key] = true;

            return;
        }

        self::write($key);
    }

    private static function write(string $key): void
    {
        self::$written[$key] = microtime(true);
        unset(self::$waiting[$key]);

        // Milliseconds since 1970: always larger than the last stamp, whichever process wrote it.
        Cache::forever($key, (int) round(microtime(true) * 1000));
    }
}
