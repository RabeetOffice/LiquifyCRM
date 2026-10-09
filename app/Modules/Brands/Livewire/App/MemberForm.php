<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Models\Target;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "Add person": create the login here with a password (the alternative to
 * an invite link), and give them a designation, a role, a department and a
 * monthly target in one step.
 */
#[Layout('components.layouts.app')]
#[Title('Add person')]
final class MemberForm extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    public Brand $brand;

    public string $name = '';

    public string $email = '';

    public string $designation = '';

    public string $phone = '';

    public string $password = '';

    public string $role = 'agent';

    public string $department = '';

    public string $targetRevenue = '';

    public string $targetCount = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('create', Membership::class);
    }

    public function save(): void
    {
        $user = $this->user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'role' => ['required', Rule::in(array_keys($this->roleOptions()))],
            'department' => [$user->isGlobalAdmin() ? 'nullable' : 'required', 'integer'],
            'targetRevenue' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'targetCount' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], attributes: ['targetRevenue' => 'revenue target', 'targetCount' => 'sales target']);

        $role = BrandRole::from($data['role']);
        $department = filled($data['department']) ? Department::query()->findOrFail((int) $data['department']) : null;

        if (! $user->isGlobalAdmin()) {
            abort_if($role !== BrandRole::Agent, 403, 'Managers can only add Agents.');
            abort_if($department === null || ! $user->managesDepartment($department), 403, 'Choose one of your own departments.');
        }

        $membership = DB::transaction(function () use ($data, $role, $department, $user): Membership {
            $person = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();

            if ($person === null) {
                $person = User::query()->create([
                    'name' => $data['name'],
                    'email' => mb_strtolower(trim($data['email'])),
                    'designation' => $data['designation'] ?: null,
                    'phone' => $data['phone'] ?: null,
                    'password' => $data['password'],
                    'created_by' => $user->getKey(),
                ]);
                $person->forceFill(['email_verified_at' => now()])->save();
            }

            return app(AddMemberToBrand::class)->handle(
                $this->brand,
                $person,
                $role,
                $department,
                $department ? DepartmentRole::from($role === BrandRole::Manager ? 'manager' : 'agent') : null,
                $user,
            );
        });

        // The monthly target in the same form, for this month.
        if ((float) ($data['targetRevenue'] ?: 0) > 0 || (int) ($data['targetCount'] ?: 0) > 0) {
            Target::query()->updateOrCreate(
                ['brand_id' => $this->brand->getKey(), 'targetable_type' => Target::USER, 'targetable_id' => $membership->user_id, 'period' => now()->format('Y-m')],
                ['revenue_target' => (float) ($data['targetRevenue'] ?: 0) > 0 ? (float) $data['targetRevenue'] : null, 'sales_count_target' => (int) ($data['targetCount'] ?: 0) > 0 ? (int) $data['targetCount'] : null, 'set_by' => $user->getKey()],
            );
        }

        $this->toastSuccess(($membership->user->name ?? 'Person').' added to '.$this->brand->name);
        $this->redirectRoute('brand.members.index', ['brand' => $this->brand->slug], navigate: true);
    }

    public function render(): View
    {
        $user = $this->user();
        $departments = Department::query()->orderBy('name')
            ->when(! $user->isGlobalAdmin(), fn (Builder $query): Builder => $query->whereIn('id', $user->managedDepartmentIds($this->brand)))
            ->pluck('name', 'id')
            ->all();

        return view('brands::livewire.app.member-form', [
            'roleOptions' => $this->roleOptions(),
            'departmentOptions' => $departments,
        ]);
    }

    /** @return array<string, string> */
    private function roleOptions(): array
    {
        $roles = $this->user()->isGlobalAdmin() ? BrandRole::assignable() : [BrandRole::Agent];

        return collect($roles)->mapWithKeys(fn (BrandRole $role): array => [$role->value => $role->getLabel()])->all();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
