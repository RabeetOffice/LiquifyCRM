<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use Illuminate\Support\Facades\Schema;

/**
 * A follow-up set in another time zone keeps that zone on tasks.timezone.
 * The code reaches the server before an Admin clicks "Update system", so
 * until the column is there a follow-up is set in the viewer's own zone as
 * before and none is shown in another.
 */
final class FollowUpZones
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasColumn('tasks', 'timezone');
    }
}
