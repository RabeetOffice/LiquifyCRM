<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\Target;
use App\Modules\Sales\Support\Progress;
use App\Modules\Sales\Support\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Targets: one month grid with the brand, each department and each person,
 * as revenue, number of sales, or both, next to live progress. The Admin sets
 * every row; a Manager sets the Agents of their own departments.
 */
#[Layout('components.layouts.app')]
#[Title('Targets')]
final class TargetsPage extends Component
{
    use WithToasts;

    public Brand $brand;

    #[Url(except: '')]
    public string $period = '';

    /** @var array<string, array{revenue: string, count: string}> "type:id" => values */
    public array $values = [];

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;

        $user = $this->user();
        abort_unless($user->isGlobalAdmin() || ($user->isManagerIn($brand) && $user->can(Permission::TargetsSet->value)), 403);

        $this->fill_();
    }

    public function shift(int $months): void
    {
        $next = CarbonImmutable::createFromFormat('!Y-m', $this->currentPeriod())->addMonths($months);

        // Targets can be planned up to a year ahead.
        if ($next->lte(now()->addYear())) {
            $this->period = $next->format('Y-m') === SalesReport::currentPeriod() ? '' : $next->format('Y-m');
            $this->fill_();
        }
    }

    public function save(): void
    {
        $this->validate([
            'values.*.revenue' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'values.*.count' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], attributes: ['values.*.revenue' => 'revenue target', 'values.*.count' => 'sales target']);

        $saved = $this->store($this->values);

        Audit::log('targets.updated', $this->brand, ['period' => $this->currentPeriod(), 'rows' => $saved], 'Targets set for '.$this->monthLabel(), brand: $this->brand, logName: 'sales');

        $this->toastSuccess('Targets saved for '.$this->monthLabel());
    }

    /** Fill every empty row with last month's target, and save. */
    public function copyLastMonth(): void
    {
        $previous = CarbonImmutable::createFromFormat('!Y-m', $this->currentPeriod())->subMonth()->format('Y-m');
        $editable = $this->editableKeys();
        $copied = 0;

        foreach (Target::query()->where('period', $previous)->get() as $target) {
            $key = Target::key($target->targetable_type, $target->targetable_id);
            $current = $this->values[$key] ?? ['revenue' => '', 'count' => ''];

            if (! in_array($key, $editable, true) || $current['revenue'] !== '' || $current['count'] !== '') {
                continue;
            }

            $this->values[$key] = [
                'revenue' => $target->revenue_target !== null ? (string) (float) $target->revenue_target : '',
                'count' => $target->sales_count_target !== null ? (string) $target->sales_count_target : '',
            ];
            $copied++;
        }

        if ($copied === 0) {
            $this->toastWarning('Nothing to copy', 'Last month has no targets, or every row here is already filled.');

            return;
        }

        $this->store($this->values);

        Audit::log('targets.copied', $this->brand, ['period' => $this->currentPeriod(), 'from' => $previous, 'rows' => $copied], 'Targets copied from last month', brand: $this->brand, logName: 'sales');

        $this->toastSuccess($copied.' '.str('target')->plural($copied).' copied from last month');
    }

    public function render(): View
    {
        $period = $this->currentPeriod();
        $rows = $this->rows();
        $editable = $this->editableKeys();

        $sales = [Target::BRAND => [], Target::DEPARTMENT => [], Target::USER => []];

        foreach ([Target::BRAND => 'brand_id', Target::DEPARTMENT => 'department_id', Target::USER => 'user_id'] as $type => $column) {
            foreach (Sale::query()->inPeriod($period)->whereNotNull($column)->groupBy($column)->toBase()->selectRaw($column.' as k, COUNT(*) as sales, COALESCE(SUM(amount), 0) as revenue')->get() as $row) {
                $sales[$type][(int) $row->k] = ['revenue' => (float) $row->revenue, 'count' => (int) $row->sales];
            }
        }

        $targets = Target::query()->where('period', $period)->get()->keyBy(fn (Target $target): string => Target::key($target->targetable_type, $target->targetable_id));

        foreach ($rows as $index => $row) {
            $achieved = $sales[$row['type']][$row['id']] ?? ['revenue' => 0.0, 'count' => 0];
            $target = $targets->get($row['key']);

            $rows[$index]['editable'] = in_array($row['key'], $editable, true);
            $rows[$index]['progress'] = new Progress(
                $achieved['revenue'],
                $achieved['count'],
                $target?->revenue_target !== null ? (float) $target->revenue_target : null,
                $target?->sales_count_target,
                $this->brand->currency,
            );
            $rows[$index]['implied'] = null;
        }

        $rows = $this->withImpliedTargets($rows);

        return view('sales::livewire.app.targets-page', [
            'rows' => $rows,
            'month' => CarbonImmutable::createFromFormat('!Y-m', $period),
            'canEdit' => $editable !== [],
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * A department, or the brand, with no target of its own measures against
     * what its people's targets add up to (a department's people; for the
     * brand, its departments as they stand), so its line is never just "No
     * target" while its people have one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withImpliedTargets(array $rows): array
    {
        $sum = fn (array $progresses): array => [
            'revenue' => array_sum(array_map(fn (Progress $p): float => (float) ($p->targetRevenue ?? 0), $progresses)),
            'count' => array_sum(array_map(fn (Progress $p): int => (int) ($p->targetCount ?? 0), $progresses)),
        ];

        $implied = function (Progress $own, array $total): ?Progress {
            if ($own->hasTarget() || ($total['revenue'] <= 0 && $total['count'] <= 0)) {
                return null;
            }

            return new Progress($own->revenue, $own->count, $total['revenue'] > 0 ? $total['revenue'] : null, $total['count'] > 0 ? $total['count'] : null, $own->currency);
        };

        $departments = [];
        $current = null;
        $people = [];

        foreach ($rows as $index => $row) {
            if ($row['level'] === 1) {
                $current = $index;
                $people[$index] = [];
            } elseif ($row['level'] === 2 && $current !== null) {
                $people[$current][] = $row['progress'];
            }
        }

        foreach ($people as $index => $progresses) {
            $rows[$index]['implied'] = $implied($rows[$index]['progress'], $sum($progresses));
            $departments[] = $rows[$index]['implied'] ?? $rows[$index]['progress'];
        }

        foreach ($rows as $index => $row) {
            if ($row['level'] === 0) {
                $rows[$index]['implied'] = $implied($row['progress'], $sum($departments));
            }
        }

        return $rows;
    }

    private function currentPeriod(): string
    {
        return SalesReport::cleanPeriod($this->period);
    }

    private function monthLabel(): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->currentPeriod())->format('F Y');
    }

    private function fill_(): void
    {
        $this->values = [];

        foreach (Target::query()->where('period', $this->currentPeriod())->get() as $target) {
            $this->values[Target::key($target->targetable_type, $target->targetable_id)] = [
                'revenue' => $target->revenue_target !== null ? (string) (float) $target->revenue_target : '',
                'count' => $target->sales_count_target !== null ? (string) $target->sales_count_target : '',
            ];
        }

        foreach ($this->rows() as $row) {
            $this->values[$row['key']] ??= ['revenue' => '', 'count' => ''];
        }
    }

    /**
     * The grid: brand, then each department followed by its people.
     *
     * @return list<array{key: string, type: string, id: int, name: string, hint: string, level: int}>
     */
    private function rows(): array
    {
        $user = $this->user();
        $admin = $user->isGlobalAdmin();
        $managed = $admin ? null : array_map('intval', $user->managedDepartmentIds($this->brand));

        $rows = [];

        if ($admin) {
            $rows[] = ['key' => Target::key(Target::BRAND, (int) $this->brand->getKey()), 'type' => Target::BRAND, 'id' => (int) $this->brand->getKey(), 'name' => $this->brand->name, 'hint' => 'Whole brand', 'level' => 0];
        }

        $departments = Department::query()
            ->when($managed !== null, fn (Builder $query): Builder => $query->whereIn('id', $managed ?? []))
            ->with(['users' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get();

        $listed = [];

        foreach ($departments as $department) {
            $rows[] = ['key' => Target::key(Target::DEPARTMENT, (int) $department->getKey()), 'type' => Target::DEPARTMENT, 'id' => (int) $department->getKey(), 'name' => $department->name, 'hint' => 'Department', 'level' => 1];

            foreach ($department->users as $person) {
                $id = (int) $person->getKey();

                // A person in two departments has one target; show them once.
                if (isset($listed[$id])) {
                    continue;
                }

                $listed[$id] = true;
                $role = $person->getRelation('pivot')->getAttribute('role');
                $rows[] = ['key' => Target::key(Target::USER, $id), 'type' => Target::USER, 'id' => $id, 'name' => $person->name, 'hint' => $role instanceof DepartmentRole ? $role->getLabel() : ucfirst((string) $role), 'level' => 2];
            }
        }

        return $rows;
    }

    /** @return list<string> keys this person may set */
    private function editableKeys(): array
    {
        $user = $this->user();

        if ($user->isGlobalAdmin()) {
            return array_column($this->rows(), 'key');
        }

        // A Manager sets their own Agents, not their own or their department's target.
        $agentIds = Department::query()
            ->whereIn('id', $user->managedDepartmentIds($this->brand))
            ->with('agents:id')
            ->get()
            ->flatMap(fn (Department $department): array => $department->agents->modelKeys())
            ->all();

        return array_values(array_map(fn (int $id): string => Target::key(Target::USER, $id), array_unique(array_map('intval', $agentIds))));
    }

    /**
     * @param  array<string, array{revenue: string, count: string}>  $values
     */
    private function store(array $values): int
    {
        $editable = $this->editableKeys();
        $saved = 0;

        foreach ($values as $key => $value) {
            if (! in_array($key, $editable, true)) {
                continue;
            }

            [$type, $id] = explode(':', $key, 2);
            $revenue = $value['revenue'] === '' ? null : (float) $value['revenue'];
            $count = $value['count'] === '' ? null : (int) $value['count'];
            $match = ['brand_id' => $this->brand->getKey(), 'targetable_type' => $type, 'targetable_id' => (int) $id, 'period' => $this->currentPeriod()];

            if (($revenue === null || $revenue <= 0) && ($count === null || $count <= 0)) {
                $saved += Target::query()->where($match)->delete() > 0 ? 1 : 0;

                continue;
            }

            Target::query()->updateOrCreate($match, ['revenue_target' => $revenue, 'sales_count_target' => $count, 'set_by' => $this->user()->getKey()]);
            $saved++;
        }

        return $saved;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
