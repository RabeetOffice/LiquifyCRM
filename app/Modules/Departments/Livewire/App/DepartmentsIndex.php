<?php

declare(strict_types=1);

namespace App\Modules\Departments\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
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

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Department::class);

        $user = auth()->user();
        abort_unless($user?->isGlobalAdmin() || $user?->isManagerIn($brand), 403);
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

        return view('departments::livewire.app.departments-index', ['departments' => $departments]);
    }
}
