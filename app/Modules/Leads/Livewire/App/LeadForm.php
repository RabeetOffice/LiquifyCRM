<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Support\AssignableAgents;
use Illuminate\Contracts\View\View;
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

        if ($departmentId !== null && Department::query()->forBrand($this->brand)->find($departmentId)?->isUpsell() !== false) {
            $this->addError('department', 'Choose a sales department: an Upsell team looks after clients, not new leads.');

            return;
        }

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
            'department_id' => $departmentId ?? $this->departmentFor($user, $assignTo),
            'assigned_to' => $assignTo,
            'source_type' => LeadSource::Manual,
        ], $user);

        // The enquiry may have joined a lead this person cannot open (another team's): say so without its name, and stay on the list.
        $canOpen = $user->can('view', $result->lead);

        if ($result->wasMerged) {
            $this->toast('Added to an existing lead', $canOpen
                ? $result->lead->name.' was already in this brand, so this enquiry went to their timeline.'
                : 'This person was already a lead of this brand that another team works, so the enquiry went to that lead.', 'info');
        } else {
            $this->toastSuccess('Lead added');
        }

        $canOpen
            ? $this->redirectRoute('brand.leads.show', ['brand' => $this->brand->slug, 'lead' => $result->lead], navigate: true)
            : $this->redirectRoute('brand.leads.index', ['brand' => $this->brand->slug], navigate: true);
    }

    /**
     * The department a new lead goes to when none was chosen: the Agent's own
     * front-sales department (for a Manager, one they run), or the Manager's
     * first department. Never an Upsell team, which works clients, not leads.
     */
    private function departmentFor(User $user, ?int $assignTo): ?int
    {
        $managed = $user->isGlobalAdmin() ? null : $user->managedDepartmentIds($this->brand);
        $ids = $assignTo !== null ? (User::query()->find($assignTo)?->departmentIds($this->brand) ?? []) : ($managed ?? []);

        if ($assignTo !== null && $managed !== null) {
            $ids = array_values(array_intersect($ids, $managed));
        }

        $department = Department::query()->forBrand($this->brand)->whereKey($ids)->orderBy('name')->get()->first(fn (Department $department): bool => ! $department->isUpsell());

        return $department !== null ? (int) $department->getKey() : null;
    }

    public function render(): View
    {
        $user = $this->user();

        return view('leads::livewire.app.lead-form', [
            'canRoute' => $user->isGlobalAdmin() || $user->isManagerIn($this->brand),
            'isAdmin' => $user->isGlobalAdmin(),
            // Upsell teams look after clients: a new lead goes to a sales department.
            'departmentOptions' => Department::query()->orderBy('name')->get()->reject(fn (Department $department): bool => $department->isUpsell())->pluck('name', 'id')->all(),
            'agentOptions' => $this->agentOptions($this->department !== '' ? (int) $this->department : null),
        ]);
    }

    /** @return array<int, string> */
    private function agentOptions(?int $departmentId): array
    {
        // The people the assign menu offers (never somebody who only works clients), narrowed to the chosen department.
        $agents = app(AssignableAgents::class)->for($this->user(), $this->brand);

        if ($departmentId === null) {
            return $agents;
        }

        $inDepartment = Department::query()->forBrand($this->brand)->find($departmentId)?->users()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all() ?? [];

        return array_filter($agents, fn (int $id): bool => in_array($id, $inDepartment, true), ARRAY_FILTER_USE_KEY);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
