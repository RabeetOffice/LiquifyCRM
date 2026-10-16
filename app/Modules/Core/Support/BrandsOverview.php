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

    /** The choices of the "Leads per day" switch. */
    public const RANGES = ['today' => 'Today', '7' => '7 days', '30' => '30 days'];

    /**
     * Leads of every brand in each hour of today, or each day of the last 7 or
     * 30 days (today included), in the viewer's time zone, for the "Leads per
     * day" chart. Brands with no lead in the range are left out; the busiest
     * come first. One query: quarter hours in UTC (dates are stored in UTC),
     * placed in the viewer's hours and days here, so summer time and
     * half-hour zones come out right.
     *
     * @return array{labels: list<string>, brands: list<array{brand: Brand, total: int, counts: list<int>}>, total: int, days: int}
     */
    public function leadsPerDay(string $range, string $timezone): array
    {
        $now = $this->now->setTimezone($timezone);
        $today = $now->startOfDay();
        $hourly = $range === 'today';
        $days = match ($range) {
            'today' => 1,
            '30' => 30,
            default => 7,
        };

        // Hours up to now for today; every day of the range otherwise.
        $slots = $hourly
            ? collect(range(0, (int) $now->format('G')))->mapWithKeys(fn (int $hour): array => [(string) $hour => sprintf('%02d:00', $hour)])
            : collect(range($days - 1, 0))->mapWithKeys(fn (int $ago): array => [$today->subDays($ago)->format('Y-m-d') => $today->subDays($ago)->format($days > 7 ? 'j M' : 'D j')]);

        $quarter = "CONCAT(DATE_FORMAT(created_at, '%Y-%m-%d %H:'), LPAD(FLOOR(MINUTE(created_at) / 15) * 15, 2, '0'))";

        $rows = Lead::query()->acrossBrands()->toBase()
            ->whereIn('brand_id', $this->brands->keys())
            ->where('created_at', '>=', $today->subDays($days - 1)->utc())
            ->where('created_at', '<', $this->now->addSecond())
            ->groupByRaw('brand_id, '.$quarter)
            ->selectRaw('brand_id, '.$quarter.' as slot, COUNT(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $local = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $row->slot, 'UTC');

            if ($local === null) {
                continue;
            }

            $local = $local->setTimezone($timezone);
            $key = $hourly ? (string) (int) $local->format('G') : $local->format('Y-m-d');
            $counts[(int) $row->brand_id][$key] = ($counts[(int) $row->brand_id][$key] ?? 0) + (int) $row->total;
        }

        $brands = collect($counts)
            ->map(fn (array $perSlot, int $id): array => [
                'brand' => $this->brands->get($id),
                'counts' => $slots->keys()->map(fn (int|string $key): int => $perSlot[(string) $key] ?? 0)->values()->all(),
            ])
            ->filter(fn (array $row): bool => $row['brand'] instanceof Brand)
            ->map(fn (array $row): array => [...$row, 'total' => array_sum($row['counts'])])
            ->filter(fn (array $row): bool => $row['total'] > 0)
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'labels' => $slots->values()->all(),
            'brands' => $brands,
            'total' => (int) array_sum(array_column($brands, 'total')),
            'days' => $days,
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
