<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
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

    /**
     * Money received in a month on the given sales, whenever they were closed.
     * The user's rule (26 Oct 2026): what counts as achieved, towards a target
     * and on the dashboards, is what clients paid; the rest of a deal is still
     * to collect and counts in the month it comes in. Before "Update system"
     * there are no payments: a sale counts in full in the month it was closed.
     *
     * @param  Builder<Sale>  $sales  the sales in scope, of any month
     * @param  string|null  $by  a column of the sale to group by; null gives one total under 0
     * @param  bool  $reporting  in the reporting currency (amount_reporting) instead of the brand's
     * @return array<int, float>
     */
    public static function received(Builder $sales, string $period, ?string $by = null, bool $reporting = false): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period) ?: now()->startOfMonth();
        $key = $by !== null ? 'sales.'.$by : '0';

        if (! self::ready()) {
            $rows = (clone $sales)->inPeriod($period)->toBase()
                ->when($by !== null, fn (QueryBuilder $query): QueryBuilder => $query->whereNotNull($key)->groupBy($key))
                ->selectRaw($key.' as k, COALESCE(SUM('.($reporting ? 'COALESCE(amount_reporting, 0)' : 'amount').'), 0) as received')
                ->get();
        } else {
            $deals = (clone $sales)->toBase()->selectRaw('sales.id as sale_id, '.$key.' as k, sales.amount as deal, sales.amount_reporting as deal_reporting');
            // In the reporting currency a payment takes its share of the converted deal.
            $amount = $reporting ? 'sale_payments.amount * COALESCE(s.deal_reporting, 0) / NULLIF(s.deal, 0)' : 'sale_payments.amount';

            $rows = DB::table('sale_payments')
                ->joinSub($deals, 's', 's.sale_id', '=', 'sale_payments.sale_id')
                ->where('sale_payments.paid_at', '>=', $start)
                ->where('sale_payments.paid_at', '<', $start->addMonth())
                ->whereNotNull('s.k')
                ->groupBy('s.k')
                ->selectRaw('s.k as k, COALESCE(SUM('.$amount.'), 0) as received')
                ->get();

            // A sale with no amount_paid has no payment rows and counts as paid in full on its day, as Sale::paid() says.
            $rows = $rows->concat((clone $sales)->inPeriod($period)->whereNull('amount_paid')->toBase()
                ->when($by !== null, fn (QueryBuilder $query): QueryBuilder => $query->whereNotNull($key)->groupBy($key))
                ->selectRaw($key.' as k, COALESCE(SUM('.($reporting ? 'COALESCE(amount_reporting, 0)' : 'amount').'), 0) as received')
                ->get());
        }

        $received = [];

        foreach ($rows as $row) {
            $received[(int) $row->k] = round(($received[(int) $row->k] ?? 0.0) + (float) $row->received, 2);
        }

        return $received;
    }
}
