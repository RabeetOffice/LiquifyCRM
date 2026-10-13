<?php

declare(strict_types=1);

namespace App\Modules\Departments\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Actions\CreateStarterTeams;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Support\StarterTeams;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Departments inside the current brand. */
#[Layout('components.layouts.app')]
#[Title('Departments')]
final class DepartmentsIndex extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithTableState;
    use WithToasts;

    public Brand $brand;

    /** @var list<string> the starter departments ticked while the brand has none (keys of StarterTeams) */
    public array $starter = [];

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Department::class);

        $user = auth()->user();
        abort_unless($user?->isGlobalAdmin() || $user?->isManagerIn($brand), 403);

        $this->starter = StarterTeams::keys();
    }

    /** A brand with no department yet: the usual ones in one click. */
    public function createStarter(CreateStarterTeams $create): void
    {
        $this->authorize('create', Department::class);

        $made = $create->handle($this->brand, array_values(array_intersect(StarterTeams::keys(), $this->starter)), auth()->user());

        if ($made === []) {
            $this->toast('Nothing created', 'Tick at least one department.', 'warning');

            return;
        }

        $this->toastSuccess(count($made).' '.str('department')->plural(count($made)).' created', 'Open each to add its Manager and people.');
    }

    public function confirmDelete(int $departmentId): void
    {
        $department = Department::query()->findOrFail($departmentId);
        $this->authorize('delete', $department);

        $this->askConfirmation('delete', [$departmentId], 'Remove '.$department->name.'?', 'Its people stay members of the brand; their leads keep their owner.', 'Remove', 'danger');
    }

    public function delete(int $departmentId): void
    {
        $department = Department::query()->findOrFail($departmentId);
        $this->authorize('delete', $department);

        $department->delete();

        Audit::log('departments.deleted', $department, ['name' => $department->name], 'Department removed', logName: 'departments');

        $this->toastSuccess($department->name.' removed');
    }

    public function render(): View
    {
        $like = '%'.$this->search.'%';

        $departments = Department::query()
            ->with('managers')
            ->withCount('agents')
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where('name', 'like', $like))
            ->orderBy($this->sortColumn(['name', 'created_at', 'agents_count'], 'name'), $this->sortDirection())
            ->paginate($this->perPage);

        return view('departments::livewire.app.departments-index', [
            'departments' => $departments,
            // Offered only while the brand has no department at all, to whoever may create one.
            'starters' => $departments->isEmpty() && $this->search === '' && auth()->user()?->can('create', Department::class) ? StarterTeams::all() : [],
        ]);
    }
}
