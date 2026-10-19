<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Support\CurrentBrand;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\Sales\Support\Invoices;
use App\Modules\Sales\Support\SalePayments;

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

    /**
     * Money received later for a part-paid sale (19 Oct 2026): whoever sold
     * it, the Manager of its department, the people who look after the client
     * now, or an Admin. It never changes the deal or the targets.
     */
    public function recordPayment(User $user, Sale $sale): bool
    {
        return SalePayments::ready() && ! $sale->isPaidInFull() && $this->involved($user, $sale);
    }

    /** An invoice for the sale (19 Oct 2026): the same people, paid in full or not. */
    public function invoice(User $user, Sale $sale): bool
    {
        return Invoices::ready() && $this->involved($user, $sale);
    }

    /** Whoever sold it, the Manager of its department, the people who look after the client now, or an Admin. */
    private function involved(User $user, Sale $sale): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        if (! app(CurrentBrand::class)->is($sale->brand_id) || ! $user->isMemberOf($sale->brand_id)) {
            return false;
        }

        if ($sale->user_id === $user->getKey()) {
            return true;
        }

        if ($sale->department_id !== null && $user->isManagerIn($sale->brand_id) && $user->managesDepartment($sale->department_id)) {
            return true;
        }

        $client = Client::query()->acrossBrands()->find($sale->client_id);

        return ClientCare::ready() && $client !== null && $user->can('care', $client);
    }
}
