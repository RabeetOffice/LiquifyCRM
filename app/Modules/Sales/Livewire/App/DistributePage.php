<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Sales\Actions\DistributeLeads;
use App\Modules\Sales\Models\LeadDistribution;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Distribute leads. The Admin filters leads and splits them across
 * departments (equally, by percentage or by count); a Manager does the same
 * inside their department, to Agents (equally or by count).
 */
#[Layout('components.layouts.app')]
#[Title('Distribute leads')]
final class DistributePage extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    /** Never move more than this in one go. */
    private const LIMIT = 5000;

    public Brand $brand;

    public string $source = '';

    public string $status = '';

    public string $from = '';

    public string $until = '';

    /** Manager: which of their departments. Admin: optional "leads already in this department". */
    public string $department = '';

    /** Admin: only leads no department has yet. Manager: only leads no Agent has yet. */
    public bool $onlyWaiting = true;

    public string $method = 'equal';

    /** @var array<int, bool> target id => included */
    public array $include = [];

    /** @var array<int, string> target id => percent or count */
    public array $amounts = [];

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorizeAccess();

        if (! $this->isAdmin()) {
            $this->department = (string) ($this->managedDepartments()->keys()->first() ?? '');
        }
    }

    public function updatedDepartment(): void
    {
        $this->include = [];
        $this->amounts = [];
    }

    public function distribute(DistributeLeads $action): void
    {
        $this->authorizeAccess();

        $this->validate([
            'method' => ['required', 'in:'.implode(',', $this->methods())],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'amounts.*' => ['nullable', 'numeric', 'min:0'],
        ], attributes: ['amounts.*' => 'amount']);

        $targets = $this->targets();
        $chosen = array_values(array_filter(array_keys($targets), fn (int $id): bool => (bool) ($this->include[$id] ?? false)));

        if ($chosen === []) {
            $this->addError('include', $this->isAdmin() ? 'Choose at least one department.' : 'Choose at least one Agent.');

            return;
        }

        $leadIds = $this->query()->oldest('id')->limit(self::LIMIT)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($leadIds === []) {
            $this->addError('include', 'No leads match these filters.');

            return;
        }

        try {
            $allocation = DistributeLeads::allocate(count($leadIds), array_combine($chosen, array_map(fn (int $id): float => (float) ($this->amounts[$id] ?? 0), $chosen)), $this->method);
        } catch (InvalidArgumentException $exception) {
            $this->addError('include', $exception->getMessage());

            return;
        }

        if ($allocation === []) {
            $this->addError('include', $this->method === 'percent' ? 'Enter a percentage for each one.' : 'Enter how many leads each one gets.');

            return;
        }

        $moved = $action->handle($this->brand, $this->user(), $leadIds, $this->isAdmin() ? DistributeLeads::TO_DEPARTMENT : DistributeLeads::TO_USER, $allocation, $this->method, $this->filters());

        $this->include = [];
        $this->amounts = [];
        $this->toastSuccess($moved.' '.str('lead')->plural($moved).' distributed', 'Each '.($this->isAdmin() ? 'department' : 'Agent').' has been told.');
    }

    public function render(): View
    {
        $targets = $this->targets();
        $matching = min($this->query()->count(), self::LIMIT);
        $chosen = array_values(array_filter(array_keys($targets), fn (int $id): bool => (bool) ($this->include[$id] ?? false)));

        try {
            $preview = $chosen === [] ? [] : DistributeLeads::allocate($matching, array_combine($chosen, array_map(fn (int $id): float => (float) ($this->amounts[$id] ?? 0), $chosen)), $this->method);
            $previewError = null;
        } catch (InvalidArgumentException $exception) {
            $preview = [];
            $previewError = $exception->getMessage();
        }

        $history = LeadDistribution::query()
            ->with('from')
            ->when(! $this->isAdmin(), fn (Builder $query): Builder => $query->where('from_user_id', $this->user()->getKey()))
            ->latest('id')
            ->limit(15)
            ->get();

        return view('sales::livewire.app.distribute-page', [
            'isAdmin' => $this->isAdmin(),
            'targets' => $targets,
            'matching' => $matching,
            'preview' => $preview,
            'previewError' => $previewError,
            'left' => $matching - array_sum($preview),
            'methods' => $this->methods(),
            'sources' => LeadSource::cases(),
            'statuses' => LeadStatus::query()->ordered()->get(),
            'departments' => $this->isAdmin() ? Department::query()->orderBy('name')->pluck('name', 'id') : $this->managedDepartments(),
            'history' => $history,
            'names' => [
                DistributeLeads::TO_DEPARTMENT => Department::query()->whereIn('id', $history->where('to_type', DistributeLeads::TO_DEPARTMENT)->pluck('to_id'))->pluck('name', 'id'),
                DistributeLeads::TO_USER => User::query()->whereIn('id', $history->where('to_type', DistributeLeads::TO_USER)->pluck('to_id'))->pluck('name', 'id'),
            ],
            'limit' => self::LIMIT,
        ]);
    }

    // -------------------------------------------------------------- internals

    /** @return Builder<Lead> */
    private function query(): Builder
    {
        $departmentId = ctype_digit($this->department) ? (int) $this->department : null;

        $query = Lead::query()
            ->open()
            ->when($this->source !== '', fn (Builder $query): Builder => $query->where('source_type', $this->source))
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status_id', (int) $this->status))
            ->when($this->from !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->until !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $this->until));

        if ($this->isAdmin()) {
            return $query
                ->when($this->onlyWaiting, fn (Builder $query): Builder => $query->whereNull('department_id'))
                ->when(! $this->onlyWaiting && $departmentId !== null, fn (Builder $query): Builder => $query->where('department_id', $departmentId));
        }

        // A Manager only ever moves leads of a department they lead.
        $allowed = $this->managedDepartments()->keys()->all();
        $departmentId = in_array($departmentId, $allowed, true) ? $departmentId : 0;

        return $query
            ->where('department_id', $departmentId)
            ->when($this->onlyWaiting, fn (Builder $query): Builder => $query->whereNull('assigned_to'));
    }

    /** @return array<int, string> target id => name: departments for the Admin, Agents for a Manager */
    private function targets(): array
    {
        if ($this->isAdmin()) {
            return Department::query()->orderBy('name')->pluck('name', 'id')->all();
        }

        $department = ctype_digit($this->department) && $this->managedDepartments()->has((int) $this->department)
            ? Department::query()->with(['agents' => fn ($query) => $query->orderBy('name')])->find((int) $this->department)
            : null;

        return $department?->agents->pluck('name', 'id')->all() ?? [];
    }

    /** @return Collection<int, string> */
    private function managedDepartments(): Collection
    {
        return Department::query()->whereIn('id', $this->user()->managedDepartmentIds($this->brand))->orderBy('name')->pluck('name', 'id');
    }

    /** @return list<string> */
    private function methods(): array
    {
        return $this->isAdmin() ? ['equal', 'percent', 'count'] : ['equal', 'count'];
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return array_filter([
            'source' => $this->source,
            'status_id' => $this->status,
            'from' => $this->from,
            'until' => $this->until,
            'department_id' => $this->department,
            'only_waiting' => $this->onlyWaiting,
        ], fn (mixed $value): bool => $value !== '' && $value !== false);
    }

    /** Admins distribute to departments; a Manager to the Agents of the departments they lead. */
    private function authorizeAccess(): void
    {
        $user = $this->user();

        abort_unless($user->isGlobalAdmin() || ($user->isManagerIn($this->brand) && $user->can(Permission::LeadsAssign->value)), 403);
    }

    private function isAdmin(): bool
    {
        return $this->user()->isGlobalAdmin();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
