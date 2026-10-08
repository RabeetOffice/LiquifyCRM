<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Manual entry. A known email or phone lands on the existing lead instead. */
#[Layout('components.layouts.app')]
#[Title('Add a lead')]
final class LeadForm extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    public Brand $brand;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $service = '';

    public string $message = '';

    public string $department = '';

    public string $assignTo = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('create', Lead::class);
    }

    public function save(): void
    {
        $this->authorize('create', Lead::class);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'service' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:5000'],
            'department' => ['nullable', 'integer'],
            'assignTo' => ['nullable', 'integer'],
        ]);

        $user = $this->user();
        $departmentId = filled($data['department']) && $user->isGlobalAdmin() ? (int) $data['department'] : null;
        $assignTo = filled($data['assignTo']) ? (int) $data['assignTo'] : null;

        if ($assignTo !== null && ! array_key_exists($assignTo, $this->agentOptions($departmentId))) {
            $this->addError('assignTo', 'Choose one of the Agents listed.');

            return;
        }

        $result = app(CreateLead::class)->handle($this->brand, [
            'name' => $data['name'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'message' => $data['message'] ?: null,
            'fields' => filled($data['service']) ? ['service' => $data['service']] : null,
            'department_id' => $departmentId ?? ($assignTo !== null ? (User::query()->find($assignTo)?->departmentIds($this->brand)[0] ?? null) : ($user->isManagerIn($this->brand) ? ($user->managedDepartmentIds($this->brand)[0] ?? null) : null)),
            'assigned_to' => $assignTo,
            'source_type' => LeadSource::Manual,
        ], $user);

        if ($result->wasMerged) {
            $this->toast('Added to an existing lead', $result->lead->name.' was already in this brand, so this enquiry went to their timeline.', 'info');
        } else {
            $this->toastSuccess('Lead added');
        }

        $this->redirectRoute('brand.leads.show', ['brand' => $this->brand->slug, 'lead' => $result->lead], navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();

        return view('leads::livewire.app.lead-form', [
            'canRoute' => $user->isGlobalAdmin() || $user->isManagerIn($this->brand),
            'isAdmin' => $user->isGlobalAdmin(),
            'departmentOptions' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
            'agentOptions' => $this->agentOptions($this->department !== '' ? (int) $this->department : null),
        ]);
    }

    /** @return array<int, string> */
    private function agentOptions(?int $departmentId): array
    {
        $user = $this->user();

        $query = User::query()
            ->whereHas('memberships', fn (Builder $query): Builder => $query->acrossBrands()->where('brand_id', $this->brand->getKey())->where('role', BrandRole::Agent->value)->where('status', 'active'))
            ->orderBy('name');

        $departmentIds = $departmentId !== null
            ? [$departmentId]
            : ($user->isGlobalAdmin() ? null : $user->managedDepartmentIds($this->brand));

        if ($departmentIds !== null) {
            $query->whereHas('departments', fn (Builder $query): Builder => $query->acrossBrands()->whereIn('departments.id', $departmentIds));
        }

        return $query->pluck('name', 'id')->all();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
