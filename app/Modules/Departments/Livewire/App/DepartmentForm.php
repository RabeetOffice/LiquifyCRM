<?php

declare(strict_types=1);

namespace App\Modules\Departments\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\DepartmentKind;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Support\ClientCare;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Create or edit a department, and (when editing) who leads it and who
 * works in it. People must already be members of the brand.
 */
#[Layout('components.layouts.app')]
final class DepartmentForm extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithToasts;

    public Brand $brand;

    public ?Department $department = null;

    public string $name = '';

    public string $color = '#FF6B2C';

    public string $description = '';

    /** Front sales (works new leads) or Upsell (looks after clients after the first sale). */
    public string $kind = 'sales';

    public string $newMemberId = '';

    public string $newMemberRole = 'agent';

    public function mount(Brand $brand, ?Department $department = null): void
    {
        $this->brand = $brand;

        if ($department !== null && $department->exists) {
            $this->department = $department;
            $this->authorize('update', $department);

            $this->name = $department->name;
            $this->color = $department->color ?? '#FF6B2C';
            $this->description = (string) $department->description;
            $this->kind = $department->kind()->value;
        } else {
            $this->authorize('create', Department::class);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments', 'name')->where('brand_id', $this->brand->getKey())->ignore($this->department?->getKey())],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'kind' => ['required', Rule::in(array_column(DepartmentKind::cases(), 'value'))],
        ], attributes: ['kind' => 'what this team does']);

        // The column arrives with "Update system"; until then every department is front sales.
        $kind = ClientCare::ready() ? ['kind' => $data['kind']] : [];

        if ($this->department === null) {
            $department = Department::query()->create([
                'brand_id' => $this->brand->getKey(),
                'name' => $data['name'],
                'color' => $data['color'] ?: null,
                'description' => $data['description'] ?: null,
                'created_by' => auth()->id(),
                ...$kind,
            ]);

            Audit::log('departments.created', $department, ['name' => $department->name], 'Department created', logName: 'departments');
            $this->toastSuccess($department->name.' created', 'Now add its Manager and Agents.');

            $this->redirectRoute('brand.departments.edit', ['brand' => $this->brand->slug, 'department' => $department], navigate: true);

            return;
        }

        $this->department->update(['name' => $data['name'], 'color' => $data['color'] ?: null, 'description' => $data['description'] ?: null, ...$kind]);

        Audit::log('departments.updated', $this->department, ['name' => $this->department->name, 'kind' => $this->department->kind()->value], 'Department updated', logName: 'departments');
        $this->toastSuccess('Department saved');
    }

    public function addMember(): void
    {
        $department = $this->requireDepartment();
        $this->authorize('manageMembers', $department);

        $this->validate([
            'newMemberId' => ['required', 'integer'],
            'newMemberRole' => ['required', Rule::in(array_column(DepartmentRole::cases(), 'value'))],
        ]);

        $user = User::query()->findOrFail((int) $this->newMemberId);
        abort_unless($user->isMemberOf($this->brand), 403);

        $role = DepartmentRole::from($this->newMemberRole);
        $department->addMember($user, $role, auth()->user());

        Audit::log('departments.member_added', $department, ['user_id' => $user->getKey(), 'role' => $role->value], "Added {$user->name} to {$department->name}", logName: 'departments');

        $this->reset('newMemberId', 'newMemberRole');
        $this->toastSuccess($user->name.' added as '.$role->getLabel());
    }

    public function changeRole(int $userId, string $role): void
    {
        $department = $this->requireDepartment();
        $this->authorize('manageMembers', $department);

        $user = User::query()->findOrFail($userId);
        $department->addMember($user, DepartmentRole::from($role), auth()->user());

        Audit::log('departments.member_role_changed', $department, ['user_id' => $userId, 'role' => $role], "{$user->name} is now ".DepartmentRole::from($role)->getLabel()." in {$department->name}", logName: 'departments');

        $this->toastSuccess('Role changed');
    }

    public function confirmRemoveMember(int $userId): void
    {
        $user = User::query()->findOrFail($userId);

        $this->askConfirmation('removeMember', [$userId], 'Remove '.$user->name.' from this department?', 'They stay a member of the brand.', 'Remove', 'danger');
    }

    public function removeMember(int $userId): void
    {
        $department = $this->requireDepartment();
        $this->authorize('manageMembers', $department);

        $user = User::query()->findOrFail($userId);
        $department->users()->detach($userId);

        Audit::log('departments.member_removed', $department, ['user_id' => $userId], "Removed {$user->name} from {$department->name}", logName: 'departments');

        $this->toastSuccess($user->name.' removed from '.$department->name);
    }

    public function render(): View
    {
        $department = $this->department?->fresh();
        $people = $department ? $department->users()->orderBy('name')->get() : collect();

        $candidates = $department
            ? User::query()
                ->whereHas('memberships', fn (Builder $query): Builder => $query->acrossBrands()->where('brand_id', $this->brand->getKey())->where('status', 'active'))
                ->whereNotIn('id', $people->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : collect();

        return view('departments::livewire.app.department-form', [
            'title' => $department ? $department->name : 'Create a department',
            'people' => $people,
            'candidates' => $candidates,
            'roles' => DepartmentRole::cases(),
            'kinds' => ClientCare::ready() ? DepartmentKind::cases() : [],
            'canManageMembers' => $department !== null && auth()->user()?->can('manageMembers', $department),
        ])->title($department ? 'Edit department' : 'Create a department');
    }

    private function requireDepartment(): Department
    {
        abort_if($this->department === null, 404);

        return $this->department;
    }
}
