<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Support\Timezones;
use Illuminate\Http\JsonResponse;

/**
 * Every time zone for a search (the clocks' "+", the follow-up's time zone),
 * fetched the first time somebody searches instead of riding along with
 * every page. The offsets change twice a year, so a day in the browser's
 * cache is fine.
 */
final class TimezonesController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(Timezones::options())->header('Cache-Control', 'private, max-age=86400');
    }
}
