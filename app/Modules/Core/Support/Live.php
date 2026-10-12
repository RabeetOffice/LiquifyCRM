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

    /** Something for this person arrived: a notification. */
    public static function touchUser(int $userId): void
    {
        self::mark('live:user:'.$userId);
    }

    /**
     * @return array{b: int, u: int} the stamp of the brand (or of all brands) and of the person
     */
    public static function stamps(?int $brandId, int $userId, bool $everyBrand = false): array
    {
        $brandKey = $brandId !== null ? 'live:brand:'.$brandId : ($everyBrand ? 'live:all' : null);
        $userKey = 'live:user:'.$userId;
        $found = Cache::many(array_values(array_filter([$brandKey, $userKey])));

        return [
            'b' => $brandKey !== null ? (int) ($found[$brandKey] ?? 0) : 0,
            'u' => (int) ($found[$userKey] ?? 0),
        ];
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
