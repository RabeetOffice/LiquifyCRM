<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\ExchangeRate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\Target;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every number on the sales dashboard for one month and one scope: all
 * brands in the reporting currency (Admin), or one brand in its own currency
 * narrowed to a Manager's departments or an Agent's own sales.
 */
final class SalesReport
{
    /** @var array<string, array<int, list<Target>>>|null type => targetable id => targets */
    private ?array $targets = null;

    /**
     * @param  Collection<int, Brand>  $brands  keyed by id
     * @param  list<int>|null  $departmentIds
     */
    public function __construct(
        public readonly string $period,
        private readonly Collection $brands,
        public readonly bool $reporting,
        private readonly ?array $departmentIds = null,
        private readonly ?int $userId = null,
    ) {}

    /** All brands (or one), in the reporting currency. */
    public static function forAdmin(string $period, ?int $brandId = null, ?int $departmentId = null): self
    {
        $brands = Brand::query()->when($brandId !== null, fn (Builder $query): Builder => $query->whereKey($brandId))->orderBy('name')->get()->keyBy('id');

        return new self($period, $brands, $brandId === null, $departmentId !== null ? [$departmentId] : null);
    }

    /** One brand in its own currency, narrowed to what the person may see. */
    public static function forBrand(Brand $brand, User $user, string $period, ?int $departmentId = null): self
    {
        $brands = collect([$brand->getKey() => $brand]);

        if ($user->isGlobalAdmin()) {
            return new self($period, $brands, false, $departmentId !== null ? [$departmentId] : null);
        }

        if ($user->isManagerIn($brand)) {
            $managed = array_map('intval', $user->managedDepartmentIds($brand));
            $ids = $departmentId !== null && in_array($departmentId, $managed, true) ? [$departmentId] : $managed;

            return new self($period, $brands, false, $ids === [] ? [0] : $ids);
        }

        return new self($period, $brands, false, null, (int) $user->getKey());
    }

    /** One person's own sales and target in one brand, whatever their role (their Profile). */
    public static function forPerson(Brand $brand, User $user, string $period): self
    {
        return new self($period, collect([$brand->getKey() => $brand]), false, null, (int) $user->getKey());
    }

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    public static function cleanPeriod(?string $period): string
    {
        return is_string($period) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) ? $period : self::currentPeriod();
    }

    public function currency(): string
    {
        return $this->reporting ? ExchangeRate::reportingCurrency() : (string) ($this->brands->first()->currency ?? ExchangeRate::reportingCurrency());
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->period) ?: now()->startOfMonth();
    }

    public function isPersonal(): bool
    {
        return $this->userId !== null;
    }

    // ------------------------------------------------------------------ cards

    /**
     * @return array{revenue: float, count: int, previous_revenue: float, previous_count: int, change: ?int}
     */
    public function totals(): array
    {
        $now = $this->sum($this->sales());
        $before = $this->sum($this->sales($this->start()->subMonth()->format('Y-m')));

        return [
            'revenue' => $now['revenue'],
            'count' => $now['count'],
            'previous_revenue' => $before['revenue'],
            'previous_count' => $before['count'],
            'change' => $before['revenue'] > 0 ? (int) round(($now['revenue'] - $before['revenue']) / $before['revenue'] * 100) : null,
        ];
    }

    /** The whole scope against its target: the brand, the department(s) or the person. */
    public function progress(): Progress
    {
        $totals = $this->sum($this->sales());

        if ($this->userId !== null) {
            $target = $this->target(Target::USER, $this->userId);
        } elseif ($this->departmentIds !== null) {
            $target = ['revenue' => null, 'count' => null];

            foreach ($this->departmentIds as $departmentId) {
                $one = $this->target(Target::DEPARTMENT, $departmentId);
                $target['revenue'] = $one['revenue'] !== null ? ($target['revenue'] ?? 0) + $one['revenue'] : $target['revenue'];
                $target['count'] = $one['count'] !== null ? ($target['count'] ?? 0) + $one['count'] : $target['count'];
            }
        } else {
            $target = ['revenue' => null, 'count' => null];

            foreach ($this->brands->keys() as $brandId) {
                $one = $this->target(Target::BRAND, (int) $brandId);
                $target['revenue'] = $one['revenue'] !== null ? ($target['revenue'] ?? 0) + $one['revenue'] : $target['revenue'];
                $target['count'] = $one['count'] !== null ? ($target['count'] ?? 0) + $one['count'] : $target['count'];
            }
        }

        return new Progress($totals['revenue'], $totals['count'], $target['revenue'], $target['count'], $this->currency());
    }

    /**
     * One row per brand: leads, conversion rate, revenue, % of target.
     *
     * @return list<array{brand: Brand, leads: int, won: int, conversion: ?int, progress: Progress}>
     */
    public function brandRows(): array
    {
        $sales = $this->grouped($this->sales(), 'brand_id');
        $leads = $this->leadCounts('brand_id');

        return $this->brands->map(function (Brand $brand) use ($sales, $leads): array {
            $id = (int) $brand->getKey();
            $target = $this->target(Target::BRAND, $id);

            return [
                'brand' => $brand,
                'leads' => $leads[$id]['leads'] ?? 0,
                'won' => $leads[$id]['won'] ?? 0,
                'conversion' => $this->rate($leads[$id]['won'] ?? 0, $leads[$id]['leads'] ?? 0),
                'progress' => new Progress($sales[$id]['revenue'] ?? 0.0, $sales[$id]['count'] ?? 0, $target['revenue'], $target['count'], $this->currency()),
            ];
        })->sortByDesc(fn (array $row): float => $row['progress']->revenue)->values()->all();
    }

    /**
     * Departments by revenue and % of target.
     *
     * @return list<array{department: Department, brand: ?Brand, progress: Progress}>
     */
    public function departmentRows(): array
    {
        $sales = $this->grouped($this->sales(), 'department_id');

        return Department::query()->acrossBrands()
            ->whereIn('brand_id', $this->brands->keys())
            ->when($this->departmentIds !== null, fn (Builder $query): Builder => $query->whereIn('id', $this->departmentIds ?? []))
            ->orderBy('name')
            ->get()
            ->map(function (Department $department) use ($sales): array {
                $id = (int) $department->getKey();
                $target = $this->target(Target::DEPARTMENT, $id);

                return [
                    'department' => $department,
                    'brand' => $this->brands->get($department->brand_id),
                    'progress' => new Progress($sales[$id]['revenue'] ?? 0.0, $sales[$id]['count'] ?? 0, $target['revenue'], $target['count'], $this->currency()),
                ];
            })
            ->sortByDesc(fn (array $row): float => $row['progress']->revenue)
            ->values()
            ->all();
    }

    /**
     * The leaderboard: people by % of target (over-achievers on top), then by revenue.
     *
     * @return list<array{user: User, leads: int, speed: ?int, progress: Progress}>
     */
    public function agentRows(int $limit = 50): array
    {
        $sales = $this->grouped($this->sales(), 'user_id');
        $leads = $this->leadCounts('assigned_to');
        $speed = $this->speedBy('assigned_to');

        $targeted = array_keys($this->targets()[Target::USER] ?? []);
        $ids = array_values(array_unique(array_filter([...array_keys($sales), ...$targeted])));

        if ($this->userId !== null) {
            $ids = [$this->userId];
        } elseif ($this->departmentIds !== null) {
            // A department view lists its own people, including those without a sale yet.
            $members = Department::query()->acrossBrands()->whereIn('id', $this->departmentIds)->with('users:id')->get()
                ->flatMap(fn (Department $department): array => $department->users->modelKeys())->all();
            $ids = array_values(array_unique([...array_intersect($ids, $members), ...$members]));
        }

        return User::query()->whereIn('id', $ids)->get()
            ->map(function (User $user) use ($sales, $leads, $speed): array {
                $id = (int) $user->getKey();
                $target = $this->target(Target::USER, $id);

                return [
                    'user' => $user,
                    'leads' => $leads[$id]['leads'] ?? 0,
                    'speed' => $speed[$id] ?? null,
                    'progress' => new Progress($sales[$id]['revenue'] ?? 0.0, $sales[$id]['count'] ?? 0, $target['revenue'], $target['count'], $this->currency()),
                ];
            })
            ->sort(fn (array $a, array $b): int => [$b['progress']->percent() ?? -1, $b['progress']->revenue] <=> [$a['progress']->percent() ?? -1, $a['progress']->revenue])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Clients by lifetime value.
     *
     * @return list<array{client: Client, brand: ?Brand, value: float}>
     */
    public function topClients(int $limit = 8): array
    {
        $query = Client::query()->acrossBrands()->whereIn('brand_id', $this->brands->keys())->where('sales_count', '>', 0);

        if ($this->departmentIds !== null || $this->userId !== null) {
            $query->whereIn('id', $this->sales(null)->select('client_id'));
        }

        return $query->get()
            ->map(fn (Client $client): array => [
                'client' => $client,
                'brand' => $this->brands->get($client->brand_id),
                'value' => $this->reporting
                    ? (float) (ExchangeRate::convert($client->lifetime_value, (string) ($this->brands->get($client->brand_id)->currency ?? ''), $this->period) ?? 0)
                    : (float) $client->lifetime_value,
            ])
            ->sortByDesc('value')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Where the month's leads came from, with the conversion rate of each source.
     *
     * @return list<array{source: LeadSource, leads: int, won: int, conversion: ?int}>
     */
    public function sources(): array
    {
        $counts = $this->leadCounts('source_type');

        return collect(LeadSource::cases())
            ->map(fn (LeadSource $source): array => [
                'source' => $source,
                'leads' => $counts[$source->value]['leads'] ?? 0,
                'won' => $counts[$source->value]['won'] ?? 0,
                'conversion' => $this->rate($counts[$source->value]['won'] ?? 0, $counts[$source->value]['leads'] ?? 0),
            ])
            ->filter(fn (array $row): bool => $row['leads'] > 0)
            ->sortByDesc('leads')
            ->values()
            ->all();
    }

    /**
     * Average minutes from a new lead to first contact, by department.
     *
     * @return list<array{department: Department, minutes: int}>
     */
    public function speed(): array
    {
        $minutes = $this->speedBy('department_id');

        return Department::query()->acrossBrands()->whereIn('id', array_keys($minutes))->orderBy('name')->get()
            ->map(fn (Department $department): array => ['department' => $department, 'minutes' => $minutes[(int) $department->getKey()]])
            ->sortBy('minutes')
            ->values()
            ->all();
    }

    /** Sales that could not be converted because no exchange rate is entered. */
    public function missingRates(): int
    {
        return $this->reporting ? $this->sales()->whereNull('amount_reporting')->count() : 0;
    }

    /**
     * The latest sales in scope.
     *
     * @return Collection<int, Sale>
     */
    public function recentSales(int $limit = 10): Collection
    {
        return $this->sales()->with(['client', 'closer', 'department'])->latest('closed_at')->limit($limit)->get();
    }

    // -------------------------------------------------------------- internals

    /** @return Builder<Sale> */
    private function sales(?string $period = ''): Builder
    {
        $period = $period === '' ? $this->period : $period;

        return Sale::query()->acrossBrands()
            ->whereIn('brand_id', $this->brands->keys())
            ->when($period !== null, fn (Builder $query): Builder => $query->inPeriod((string) $period))
            ->when($this->departmentIds !== null, fn (Builder $query): Builder => $query->whereIn('department_id', $this->departmentIds ?? []))
            ->when($this->userId !== null, fn (Builder $query): Builder => $query->where('user_id', $this->userId));
    }

    /** Leads that arrived in the month, in scope. @return Builder<Lead> */
    private function leads(): Builder
    {
        $lead = new Lead;

        return Lead::query()->acrossBrands()
            ->whereIn($lead->qualifyColumn('brand_id'), $this->brands->keys())
            ->where($lead->qualifyColumn('created_at'), '>=', $this->start())
            ->where($lead->qualifyColumn('created_at'), '<', $this->start()->addMonth())
            ->when($this->departmentIds !== null, fn (Builder $query): Builder => $query->whereIn($lead->qualifyColumn('department_id'), $this->departmentIds ?? []))
            ->when($this->userId !== null, fn (Builder $query): Builder => $query->where($lead->qualifyColumn('assigned_to'), $this->userId));
    }

    private function amount(): string
    {
        return $this->reporting ? 'COALESCE(amount_reporting, 0)' : 'amount';
    }

    /**
     * @param  Builder<Sale>  $query
     * @return array{revenue: float, count: int}
     */
    private function sum(Builder $query): array
    {
        $row = $query->toBase()->selectRaw('COUNT(*) as sales, COALESCE(SUM('.$this->amount().'), 0) as revenue')->first();

        return ['revenue' => (float) ($row->revenue ?? 0), 'count' => (int) ($row->sales ?? 0)];
    }

    /**
     * @param  Builder<Sale>  $query
     * @return array<int, array{revenue: float, count: int}>
     */
    private function grouped(Builder $query, string $column): array
    {
        $rows = [];

        foreach ($query->toBase()->whereNotNull($column)->groupBy($column)->selectRaw($column.' as k, COUNT(*) as sales, COALESCE(SUM('.$this->amount().'), 0) as revenue')->get() as $row) {
            $rows[(int) $row->k] = ['revenue' => (float) $row->revenue, 'count' => (int) $row->sales];
        }

        return $rows;
    }

    /**
     * Leads of the month and how many of them are Won, grouped by a lead column.
     *
     * @return array<int|string, array{leads: int, won: int}>
     */
    private function leadCounts(string $column): array
    {
        $rows = [];

        $query = $this->leads()
            ->join('lead_statuses', 'lead_statuses.id', '=', 'leads.status_id')
            ->whereNotNull('leads.'.$column)
            ->groupBy('leads.'.$column)
            ->toBase()
            ->selectRaw('leads.'.$column.' as k, COUNT(*) as leads, SUM(CASE WHEN lead_statuses.is_won = 1 THEN 1 ELSE 0 END) as won');

        foreach ($query->get() as $row) {
            $rows[$row->k] = ['leads' => (int) $row->leads, 'won' => (int) $row->won];
        }

        return $rows;
    }

    /** @return array<int, int> average minutes to first contact */
    private function speedBy(string $column): array
    {
        $rows = [];

        $query = $this->leads()
            ->whereNotNull('leads.first_contacted_at')
            ->whereNotNull('leads.'.$column)
            ->groupBy('leads.'.$column)
            ->toBase()
            ->selectRaw('leads.'.$column.' as k, AVG(TIMESTAMPDIFF(MINUTE, leads.created_at, leads.first_contacted_at)) as minutes');

        foreach ($query->get() as $row) {
            $rows[(int) $row->k] = max(0, (int) round((float) $row->minutes));
        }

        return $rows;
    }

    /** @return array<string, array<int, list<Target>>> type => targetable id => targets */
    private function targets(): array
    {
        if ($this->targets !== null) {
            return $this->targets;
        }

        $this->targets = [];

        foreach (Target::query()->acrossBrands()->where('period', $this->period)->whereIn('brand_id', $this->brands->keys())->get() as $target) {
            $this->targets[$target->targetable_type][$target->targetable_id][] = $target;
        }

        return $this->targets;
    }

    /**
     * A target in the dashboard's currency. A person in two brands has their targets added up.
     *
     * @return array{revenue: ?float, count: ?int}
     */
    private function target(string $type, int $id): array
    {
        $revenue = null;
        $count = null;

        foreach ($this->targets()[$type][$id] ?? [] as $target) {
            if ($target->revenue_target !== null) {
                $currency = (string) ($this->brands->get($target->brand_id)->currency ?? $this->currency());
                $value = $this->reporting ? (float) (ExchangeRate::convert($target->revenue_target, $currency, $this->period) ?? 0) : (float) $target->revenue_target;
                $revenue = ($revenue ?? 0) + $value;
            }

            if ($target->sales_count_target !== null) {
                $count = ($count ?? 0) + $target->sales_count_target;
            }
        }

        return ['revenue' => $revenue, 'count' => $count];
    }

    private function rate(int $won, int $leads): ?int
    {
        return $leads > 0 ? (int) round($won / $leads * 100) : null;
    }
}
