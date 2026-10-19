<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Part payments need the sale_payments table and sales.amount_paid. The code
 * reaches the server before an Admin clicks "Update system", so everything
 * that touches them asks here first and behaves as before until they exist:
 * every sale counts as paid in full and there is no "Paid now".
 */
final class SalePayments
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        // The last column of the update: when it is there, the rest is too.
        return self::$ready = self::$ready || Schema::hasColumn('sales', 'amount_paid');
    }
}
