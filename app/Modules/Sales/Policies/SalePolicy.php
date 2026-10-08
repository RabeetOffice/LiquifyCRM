<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Sales\Models\Sale;

/**
 * Correcting or removing a recorded sale changes targets and lifetime value,
 * so only a Super Admin or Admin may do it (decided 8 October 2026).
 * Managers and Agents record sales; they cannot change them afterwards.
 */
final class SalePolicy
{
    public function update(User $user, Sale $sale): bool
    {
        return $user->isGlobalAdmin();
    }

    public function delete(User $user, Sale $sale): bool
    {
        return $user->isGlobalAdmin();
    }
}
