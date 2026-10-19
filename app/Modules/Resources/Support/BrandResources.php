<?php

declare(strict_types=1);

namespace App\Modules\Resources\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Resources need their own table. The code reaches the server before an
 * Admin clicks "Update system", so the menu item and the screen ask here
 * first and stay out of the way until it exists.
 */
final class BrandResources
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('resources');
    }
}
