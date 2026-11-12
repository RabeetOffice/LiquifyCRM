<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * "Is this table (or column) there yet?" without asking the database on every request. New code reaches the server
 * before an Admin clicks "Update system", so each feature asks before it touches its new tables; asked through
 * Schema, that was up to fifteen slow questions a page. Here a yes is remembered for good in one cache entry (read
 * once a request), because a table that exists stays. A no is never remembered: it is asked again until the update
 * has run. "Update system" clears the cache, so the list starts again after every update.
 */
final class KnownSchema
{
    private const KEY = 'schema:known';

    /** @var array<string, true>|null */
    private static ?array $known = null;

    public static function table(string $table): bool
    {
        return self::answer($table, fn (): bool => Schema::hasTable($table));
    }

    public static function column(string $table, string $column): bool
    {
        return self::answer($table.'.'.$column, fn (): bool => Schema::hasColumn($table, $column));
    }

    /** Forgets everything, in this request and in the cache (tests, and whoever changes the database by hand). */
    public static function forget(): void
    {
        self::$known = null;

        try {
            Cache::forget(self::KEY);
        } catch (Throwable) {
            // No cache to forget.
        }
    }

    /** @param  callable(): bool  $ask */
    private static function answer(string $name, callable $ask): bool
    {
        self::$known ??= self::remembered();

        if (isset(self::$known[$name])) {
            return true;
        }

        if (! $ask()) {
            return false;
        }

        self::$known[$name] = true;

        try {
            // Another request may have learnt other names meanwhile: keep theirs too.
            self::$known += self::remembered();
            Cache::forever(self::KEY, self::$known);
        } catch (Throwable) {
            // The cache is away: the answer still holds for this request.
        }

        return true;
    }

    /** @return array<string, true> */
    private static function remembered(): array
    {
        try {
            $known = Cache::get(self::KEY);
        } catch (Throwable) {
            return [];
        }

        return is_array($known) ? array_fill_keys(array_map(strval(...), array_keys($known)), true) : [];
    }
}
