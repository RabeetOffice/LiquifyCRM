<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use App\Modules\Sales\Enums\SaleKind;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\Target;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\Sales\Support\Progress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The admin Overview: every brand's month side by side, each in its own
 * currency (money is never added up across brands). Only brands that were
 * not removed count, so the totals match the Brands screen. One grouped
 * query per number, however many brands there are.
 *
 * "Compared with last month" means the same days of last month (1 to 15
 * October against 1 to 15 September), so the first days of a month do
 * not look like a fall.
 */
final class BrandsOverview
{
    /**
     * @param  Collection<int, Brand>  $brands  keyed by id
     */
    private function __construct(
        private readonly Collection $brands,
        private readonly CarbonImmutable $now,
    ) {}

    public static function make(): self
    {
        return new self(Brand::query()->orderBy('name')->get()->keyBy('id'), CarbonImmutable::now());
    }

    /**
     * One row per brand: active ones first, then the busiest.
     *
     * @return list<array{brand: Brand, leads: int, previous: int, unassigned: int, overdue: int, sales: int, upsells: int, progress: Progress}>
     */
    public function rows(): array
    {
        $start = $this->now->startOfMonth();
        $leads = $this->countLeads($start, $this->now->addSecond());
        $previous = $this->countLeads($start->subMonthNoOverflow(), $this->now->subMonthNoOverflow());
        $unassigned = $this->counts(Lead::query()->acrossBrands()->unassigned());
        $overdue = $this->counts(Task::query()->acrossBrands()->overdue()->whereIn('lead_id', Lead::query()->acrossBrands()->select('id')));
        $sales = $this->sales($start);
        $targets = $this->targets($start->format('Y-m'));

        return $this->brands
            ->map(function (Brand $brand) use ($leads, $previous, $unassigned, $overdue, $sales, $targets): array {
                $id = (int) $brand->getKey();
                $sold = $sales[$id] ?? ['count' => 0, 'upsells' => 0, 'revenue' => 0.0];
                $target = $targets[$id] ?? ['revenue' => null, 'count' => null];

                return [
                    'brand' => $brand,
                    'leads' => $leads[$id] ?? 0,
                    'previous' => $previous[$id] ?? 0,
                    'unassigned' => $unassigned[$id] ?? 0,
                    'overdue' => $overdue[$id] ?? 0,
                    'sales' => $sold['count'],
                    'upsells' => $sold['upsells'],
                    'progress' => new Progress($sold['revenue'], $sold['count'], $target['revenue'], $target['count'], (string) $brand->currency),
                ];
            })
            ->sortBy([fn (array $a, array $b): int => $b['brand']->is_active <=> $a['brand']->is_active, fn (array $a, array $b): int => $b['leads'] <=> $a['leads']])
            ->values()
            ->all();
    }

    /**
     * Leads per brand over the last 30 days, the busiest first, for the chart.
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function lastThirtyDays(int $limit = 8): array
    {
        $counts = collect($this->countLeads($this->now->subDays(30), $this->now->addSecond()))
            ->filter(fn (int $count): bool => $count > 0)
            ->sortDesc()
            ->take($limit);

        return [
            'labels' => $counts->keys()->map(fn (int $id): string => (string) $this->brands->get($id)?->name)->values()->all(),
            'values' => $counts->values()->all(),
        ];
    }

    /** @return array<int, int> brand id => leads that arrived in [from, to) */
    private function countLeads(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->counts(Lead::query()->acrossBrands()->where('created_at', '>=', $from)->where('created_at', '<', $to));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array<int, int> brand id => rows
     */
    private function counts(Builder $query): array
    {
        $rows = $query->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->groupBy('brand_id')
            ->selectRaw('brand_id, COUNT(*) as total')
            ->pluck('total', 'brand_id');

        $counts = [];

        foreach ($rows as $brandId => $total) {
            $counts[(int) $brandId] = (int) $total;
        }

        return $counts;
    }

    /** @return array<int, array{count: int, upsells: int, revenue: float}> brand id => the month's sales in its own currency */
    private function sales(CarbonImmutable $start): array
    {
        $upsells = ClientCare::ready() ? 'SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END)' : '0';

        $rows = Sale::query()->acrossBrands()->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $start->addMonth())
            ->groupBy('brand_id')
            ->selectRaw('brand_id, COUNT(*) as sales, '.$upsells.' as upsells, COALESCE(SUM(amount), 0) as revenue', ClientCare::ready() ? [SaleKind::Upsell->value] : [])
            ->get();

        $sales = [];

        foreach ($rows as $row) {
            $sales[(int) $row->brand_id] = ['count' => (int) $row->sales, 'upsells' => (int) $row->upsells, 'revenue' => (float) $row->revenue];
        }

        return $sales;
    }

    /** @return array<int, array{revenue: ?float, count: ?int}> brand id => the brand's own target for the month */
    private function targets(string $period): array
    {
        $targets = [];

        $rows = Target::query()->acrossBrands()
            ->where('period', $period)
            ->where('targetable_type', Target::BRAND)
            ->whereIn('targetable_id', $this->brands->keys())
            ->get();

        foreach ($rows as $target) {
            $targets[(int) $target->targetable_id] = [
                'revenue' => $target->revenue_target !== null ? (float) $target->revenue_target : null,
                'count' => $target->sales_count_target,
            ];
        }

        return $targets;
    }
}
