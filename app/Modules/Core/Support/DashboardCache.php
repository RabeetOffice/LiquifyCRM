<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard numbers are cached per brand for 60 seconds and thrown away the
 * moment a lead arrives, is assigned or changes status, so the screen is
 * fast with 100,000 leads and still never shows a stale queue.
 */
final class DashboardCache
{
    public const SECONDS = 60;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public static function remember(int $brandId, string $scope, Closure $compute): mixed
    {
        return Cache::remember('dashboard:'.$brandId.':'.self::version($brandId).':'.$scope, self::SECONDS, $compute);
    }

    /** Every cached number of the brand becomes unreachable at once. */
    public static function flush(int $brandId): void
    {
        Cache::forever('dashboard-version:'.$brandId, self::version($brandId) + 1);
    }

    private static function version(int $brandId): int
    {
        return (int) Cache::get('dashboard-version:'.$brandId, 1);
    }
}
