<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Lead routing, Upsell departments and the client's hand-over, upsells and
 * storage arrived together and need their tables. New code reaches the
 * server before an Admin clicks "Update system", so everything that touches
 * those tables asks here first and behaves as before until they exist.
 */
final class ClientCare
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        // The last table of the update: when it is there, the rest is too.
        return self::$ready = self::$ready || Schema::hasTable('client_notes');
    }
}
