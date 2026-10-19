<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Archived leads need two new columns on leads. The code reaches the server
 * before an Admin clicks "Update system", so everything that touches them
 * asks here first and behaves as before until they exist: nothing is
 * archived and there is no Archive button.
 */
final class LeadArchive
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        // The last column of the update: when it is there, the rest is too.
        return self::$ready = self::$ready || Schema::hasColumn('leads', 'archived_by');
    }
}
