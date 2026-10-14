<?php

declare(strict_types=1);

namespace App\Modules\Meta\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Instagram direct messages need two new columns (the account linked to a
 * Page, the person's @username). The code reaches the server before an Admin
 * clicks "Update system", so everything that touches them asks here first and
 * behaves as before until they exist: Instagram events are kept and wait.
 */
final class Instagram
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        // The last column of the update: when it is there, the rest is too.
        return self::$ready = self::$ready || Schema::hasColumn('conversations', 'contact_handle');
    }
}
