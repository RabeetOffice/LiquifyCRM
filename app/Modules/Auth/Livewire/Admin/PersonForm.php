<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire\Admin;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Sales\Models\Target;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Add or edit a person from the admin area. One form for every kind of
 * account: an Admin (all brands), or a Manager or Agent placed in a brand
 * and a department with their designation and monthly target.
 */
#[Layout('components.layouts.app')]
final class PersonForm extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    public ?User $account = null;

    public string $name = '';

    public string $email = '';

    public string $designation = '';

    public string $phone = '';

    public string $password = '';

    /** admin | manager | agent (and super_admin when editing one) */
    public string $kind = 'agent';

    public string $status = 'active';

    public string $brandId = '';

    public string $departmentId = '';

    public string $targetRevenue = '';

    public string $targetCount = '';

    public function mount(?int $userId = null): void
    {
        if ($userId === null) {
            $this->authorize('create', User::class);
            $this->kind = in_array(request()->query('as'), ['admin', 'manager', 'agent'], true) ? (string) request()->query('as') : 'agent';
            $this->brandId = ctype_digit((string) request()->query('brand')) ? (string) request()->query('brand') : '';

            return;
        }

        $this->account = User::query()->findOrFail($userId);
        $this->authorize('update', $this->account);

        $this->name = $this->account->name;
        $this->email = $this->account->email;
        $this->designation = (string) $this->account->designation;
        $this->phone = (string) $this->account->phone;
        $this->status = $this->account->status->value;
        $this->kind = $this->account->role->value ?? 'member';
    }

    public function updatedBrandId(): void
    {
        $this->departmentId = '';
    }

    public function save(): void
    {
        $this->account === null ? $this->create() : $this->update();
    }

    public function render(): View
    {
        $brand = ctype_digit($this->brandId) ? Brand::query()->find((int) $this->brandId) : null;
        $title = $this->account ? 'Edit '.$this->account->name : 'Add person';

        return view('auth::livewire.admin.person-form', [
            'title' => $title,
            'locked' => $this->locked(),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'currency']),
            'brand' => $brand,
            'departments' => $brand !== null ? Department::query()->forBrand($brand)->orderBy('name')->pluck('name', 'id')->all() : [],
            'statuses' => MembershipStatus::cases(),
            'canMakeSuperAdmin' => (bool) auth()->user()?->can('assignGlobalRole', [User::class, GlobalRole::SuperAdmin]),
            'memberships' => $this->account !== null
                ? Membership::query()->acrossBrands()->where('user_id', $this->account->getKey())->with('brand')->get()
                : collect(),
        ])->title($title);
    }

    private function create(): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $isAdmin = $this->kind === 'admin';

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', $isAdmin ? Password::min(12)->letters()->numbers() : Password::min(10)->letters()->numbers()],
            'kind' => ['required', Rule::in(['admin', 'manager', 'agent'])],
            'brandId' => [$isAdmin ? 'nullable' : 'required', 'integer'],
            'departmentId' => ['nullable', 'integer'],
            'targetRevenue' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'targetCount' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], attributes: ['brandId' => 'brand', 'departmentId' => 'department', 'targetRevenue' => 'revenue target', 'targetCount' => 'sales target']);

        $email = mb_strtolower(trim($data['email']));
        $existing = User::query()->where('email', $email)->first();

        if ($isAdmin) {
            abort_unless($actor->can('assignGlobalRole', [User::class, GlobalRole::Admin]), 403);

            if ($existing !== null) {
                throw ValidationException::withMessages(['email' => 'This email address already has an account.']);
            }

            $person = User::query()->create([
                'name' => $data['name'], 'email' => $email, 'password' => $data['password'],
                'designation' => $data['designation'] ?: null, 'phone' => $data['phone'] ?: null,
                'role' => GlobalRole::Admin, 'created_by' => $actor->getKey(),
            ]);
            $person->forceFill(['email_verified_at' => now()])->save();

            Audit::log('members.admin_created', $person, ['email' => $person->email, 'role' => GlobalRole::Admin->value], 'Admin account created', logName: 'members');
            $this->toastSuccess($person->name.' added as Admin', 'They will be asked to set up an authenticator app on first sign-in.');
            $this->redirectRoute('admin.people.index', navigate: true);

            return;
        }

        if ($existing !== null && $existing->isGlobalAdmin()) {
            throw ValidationException::withMessages(['email' => 'This person is an Admin and already sees every brand.']);
        }

        $brand = Brand::query()->findOrFail((int) $data['brandId']);
        $department = filled($data['departmentId']) ? Department::query()->forBrand($brand)->findOrFail((int) $data['departmentId']) : null;
        $role = BrandRole::from($data['kind']);

        $person = DB::transaction(function () use ($data, $email, $existing, $brand, $department, $role, $actor): User {
            $person = $existing ?? User::query()->create([
                'name' => $data['name'], 'email' => $email, 'password' => $data['password'],
                'designation' => $data['designation'] ?: null, 'phone' => $data['phone'] ?: null,
                'created_by' => $actor->getKey(),
            ]);

            if ($existing === null) {
                $person->forceFill(['email_verified_at' => now()])->save();
            }

            app(AddMemberToBrand::class)->handle(
                $brand,
                $person,
                $role,
                $department,
                $department ? ($role === BrandRole::Manager ? DepartmentRole::Manager : DepartmentRole::Agent) : null,
                $actor,
            );

            $revenue = (float) ($data['targetRevenue'] ?: 0);
            $count = (int) ($data['targetCount'] ?: 0);

            if ($revenue > 0 || $count > 0) {
                Target::query()->acrossBrands()->updateOrCreate(
                    ['brand_id' => $brand->getKey(), 'targetable_type' => Target::USER, 'targetable_id' => $person->getKey(), 'period' => now()->format('Y-m')],
                    ['revenue_target' => $revenue > 0 ? $revenue : null, 'sales_count_target' => $count > 0 ? $count : null, 'set_by' => $actor->getKey()],
                );
            }

            return $person;
        });

        $this->toastSuccess($person->name.' added to '.$brand->name, $existing !== null ? 'They already had an account, so their password was left unchanged.' : null);
        $this->redirectRoute('admin.people.index', navigate: true);
    }

    private function update(): void
    {
        /** @var User $account */
        $account = $this->account;
        $locked = $this->locked();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account->getKey())],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'status' => ['required', Rule::in(array_column(MembershipStatus::cases(), 'value'))],
            'kind' => ['required', 'string'],
            'password' => ['nullable', 'string', Password::min($account->isGlobalAdmin() ? 12 : 10)->letters()->numbers()],
        ]);

        $attributes = [
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
            'designation' => $data['designation'] ?: null,
            'phone' => $data['phone'] ?: null,
        ];

        if (! $locked) {
            $attributes['status'] = MembershipStatus::from($data['status']);

            // Only an existing admin's global role can change here, and only to a role the actor may grant.
            if ($account->isGlobalAdmin() && in_array($data['kind'], ['admin', 'super_admin'], true)) {
                $role = GlobalRole::from($data['kind']);
                abort_unless(auth()->user()?->can('assignGlobalRole', [User::class, $role]), 403);
                $attributes['role'] = $role;
                $attributes['is_protected'] = $role === GlobalRole::SuperAdmin;
            }
        }

        if (filled($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        $account->forceFill($attributes)->save();

        Audit::log($account->isGlobalAdmin() ? 'members.admin_updated' : 'members.profile_updated', $account, ['email' => $account->email], 'Account updated', logName: 'members');
        $this->toastSuccess('Account saved');
        $this->redirectRoute('admin.people.index', navigate: true);
    }

    /** A Super Admin's role and status, and your own, cannot be changed from this form. */
    private function locked(): bool
    {
        return $this->account !== null && ($this->account->isSuperAdmin() || $this->account->is(auth()->user()));
    }
}
