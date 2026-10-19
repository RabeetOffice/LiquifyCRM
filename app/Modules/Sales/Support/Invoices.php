<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Invoices need their table. The code reaches the server before an Admin
 * clicks "Update system", so the Create invoice button asks here first and
 * stays away until it exists.
 */
final class Invoices
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        return self::$ready = self::$ready || Schema::hasTable('invoices');
    }
}
