<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire\Admin;

use App\Modules\Auth\Actions\ChangeSignInRules;
use App\Modules\Auth\Enums\NetworkRule;
use App\Modules\Auth\Enums\PhoneRule;
use App\Modules\Auth\Models\SignInDevice;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\SignInGate;
use App\Modules\Auth\Support\SignInRules;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Actions\ChangeBrandAccess;
use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Support\WebsiteLeads;
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
 * and a department with their designation and monthly target. On a Manager
 * or Agent, an Admin also shares more brands with them, each with its own
 * role and department (only Admins may: UserPolicy::shareBrands).
 */
#[Layout('components.layouts.app')]
final class PersonForm extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithToasts;

    public ?User $account = null;

    public string $name = '';

    public string $email = '';

    public string $designation = '';

    public string $phone = '';

    public string $password = '';

    /** admin | it | website_leads | manager | agent (and super_admin when editing one) */
    public string $kind = 'agent';

    /**
     * The brands whose website leads a Website leads person reads (the ticked boxes).
     *
     * @var array<int, string>
     */
    public array $leadBrandIds = [];

    public string $status = 'active';

    public string $brandId = '';

    public string $departmentId = '';

    public string $targetRevenue = '';

    public string $targetCount = '';

    /** Another brand to share with an existing Manager or Agent, their role there and department. */
    public string $newBrandId = '';

    public string $newBrandRole = 'agent';

    public string $newDepartmentId = '';

    /** Their own sign-in rules ('' = as for everybody): NetworkRule and PhoneRule values. */
    public string $signInNetwork = '';

    public string $signInPhones = '';

    /** What can be chosen for a new person. */
    private const KINDS = ['admin', 'it', 'website_leads', 'manager', 'agent'];

    /** The kinds that work outside the brands, with a role of their own (users.role). */
    private const GLOBAL_KINDS = ['admin', 'it', 'website_leads'];

    public function mount(?int $userId = null): void
    {
        if ($userId === null) {
            $this->authorize('create', User::class);
            $this->kind = in_array(request()->query('as'), self::KINDS, true) ? (string) request()->query('as') : 'agent';
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

        if ($this->account->isWebsiteLeads()) {
            $this->leadBrandIds = array_map('strval', WebsiteLeads::chosenBrandIds($this->account));
        }

        if (SignInRules::ready()) {
            $this->signInNetwork = (string) $this->account->getAttribute('sign_in_network');
            $this->signInPhones = (string) $this->account->getAttribute('sign_in_phones');
        }
    }

    public function updatedBrandId(): void
    {
        $this->departmentId = '';
    }

    public function updatedNewBrandId(): void
    {
        $this->newDepartmentId = '';
    }

    // ----------------------------------------------------------- shared brands

    /** Give the person one more brand, with their role and department there. */
    public function addBrand(AddMemberToBrand $add): void
    {
        $person = $this->sharedAccount();

        $data = $this->validate([
            'newBrandId' => ['required', 'integer'],
            'newBrandRole' => ['required', Rule::in(array_column(BrandRole::assignable(), 'value'))],
            'newDepartmentId' => ['nullable', 'integer'],
        ], attributes: ['newBrandId' => 'brand', 'newBrandRole' => 'role', 'newDepartmentId' => 'department']);

        $brand = Brand::query()->findOrFail((int) $data['newBrandId']);

        if ($person->membershipFor($brand) !== null) {
            throw ValidationException::withMessages(['newBrandId' => $person->name.' is already in '.$brand->name.'.']);
        }

        $role = BrandRole::from($data['newBrandRole']);
        $department = filled($data['newDepartmentId']) ? Department::query()->forBrand($brand)->findOrFail((int) $data['newDepartmentId']) : null;

        $add->handle($brand, $person, $role, $department, $department ? ChangeBrandAccess::seatRole($role) : null, $this->actor());

        $this->reset('newBrandId', 'newDepartmentId');
        $this->newBrandRole = 'agent';
        $this->toastSuccess($person->name.' can now work in '.$brand->name, 'As '.$role->getLabel().($department ? ' in '.$department->name : '').'.');
    }

    public function changeBrandRole(int $membershipId, string $role, ChangeBrandAccess $change): void
    {
        $membership = $this->sharedMembership($membershipId);
        $newRole = BrandRole::tryFrom($role);
        abort_unless(in_array($newRole, BrandRole::assignable(), true), 422);

        $change->role($membership, $newRole, $this->actor());

        $this->toastSuccess('Now '.$newRole->getLabel().' in '.($membership->brand->name ?? 'this brand'));
    }

    public function changeBrandDepartment(int $membershipId, string $departmentId, ChangeBrandAccess $change): void
    {
        $membership = $this->sharedMembership($membershipId);
        /** @var Brand $brand */
        $brand = $membership->brand;
        $department = ctype_digit($departmentId) ? Department::query()->forBrand($brand)->findOrFail((int) $departmentId) : null;

        $change->department($membership, $department, $this->actor());

        $this->toastSuccess($department !== null ? 'Moved to '.$department->name : 'Taken out of the departments of '.$brand->name);
    }

    public function confirmRemoveBrand(int $membershipId): void
    {
        $membership = $this->sharedMembership($membershipId);
        $brandName = $membership->brand->name ?? 'this brand';
        $last = Membership::query()->acrossBrands()->where('user_id', $membership->user_id)->count() === 1;

        $this->askConfirmation(
            'removeBrand',
            [$membershipId],
            'Remove '.$this->sharedAccount()->name.' from '.$brandName.'?',
            'They lose access to '.$brandName.' at once and their open leads there go back to the queue. Their other brands stay as they are.'.($last ? ' It is their only brand: they will see nothing until you add one.' : ''),
            'Remove',
            'danger',
        );
    }

    public function removeBrand(int $membershipId, RemoveMemberFromBrand $remove): void
    {
        $membership = $this->sharedMembership($membershipId);
        $brandName = $membership->brand->name ?? 'the brand';

        $remove->handle($membership, $this->actor());

        $this->toastSuccess('Removed from '.$brandName);
    }

    public function save(): void
    {
        $this->account === null ? $this->create() : $this->update();
    }

    public function render(): View
    {
        $brand = ctype_digit($this->brandId) ? Brand::query()->find((int) $this->brandId) : null;
        $title = $this->account ? 'Edit '.$this->account->name : 'Add person';
        $shared = $this->sharedBrands();
        $canShare = $this->account !== null && (bool) auth()->user()?->can('shareBrands', $this->account);
        $newBrand = $canShare && ctype_digit($this->newBrandId) ? Brand::query()->find((int) $this->newBrandId) : null;

        return view('auth::livewire.admin.person-form', [
            'title' => $title,
            'locked' => $this->locked(),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'currency']),
            'brand' => $brand,
            'departments' => $brand !== null ? Department::query()->forBrand($brand)->orderBy('name')->pluck('name', 'id')->all() : [],
            'statuses' => MembershipStatus::cases(),
            'canMakeSuperAdmin' => (bool) auth()->user()?->can('assignGlobalRole', [User::class, GlobalRole::SuperAdmin]),
            'shared' => $shared,
            'canShare' => $canShare,
            'roles' => BrandRole::assignable(),
            // Brands the person is not in yet, and the departments of the one chosen to add.
            'otherBrands' => $canShare
                ? Brand::query()->where('is_active', true)->whereNotIn('id', array_map(fn (array $row): int => (int) $row['brand']->getKey(), $shared))->orderBy('name')->get(['id', 'name'])
                : collect(),
            'newDepartments' => $newBrand !== null ? Department::query()->forBrand($newBrand)->orderBy('name')->pluck('name', 'id')->all() : [],
            'signIn' => SignInRules::ready() ? $this->signInFacts() : null,
            'websiteLeadsReady' => WebsiteLeads::ready(),
        ])->title($title);
    }

    /**
     * The rule for everybody, the choices and the person's phones, for the Sign-in card.
     *
     * @return array{rules: SignInRules, networks: list<NetworkRule>, phones: list<PhoneRule>, devices: array<string, int>}
     */
    private function signInFacts(): array
    {
        $devices = $this->account === null ? [] : SignInDevice::query()->where('user_id', $this->account->getKey())
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total): int => (int) $total)->all();

        return ['rules' => SignInRules::current(), 'networks' => NetworkRule::cases(), 'phones' => PhoneRule::cases(), 'devices' => $devices];
    }

    /**
     * The person's own sign-in rules as chosen on the form, refused when the
     * Admin is changing their own and it would sign them out.
     *
     * @return array{0: NetworkRule|null, 1: PhoneRule|null}
     */
    private function chosenSignInRules(): array
    {
        $this->validate([
            'signInNetwork' => ['nullable', Rule::enum(NetworkRule::class)],
            'signInPhones' => ['nullable', Rule::enum(PhoneRule::class)],
        ], attributes: ['signInNetwork' => 'where they can sign in from', 'signInPhones' => 'phones and tablets']);

        $network = NetworkRule::tryFrom($this->signInNetwork);
        $phones = PhoneRule::tryFrom($this->signInPhones);

        if ($this->account !== null && $this->account->is(auth()->user())) {
            $me = (clone $this->account)->forceFill(['sign_in_network' => $network?->value, 'sign_in_phones' => $phones?->value]);

            if (app(SignInGate::class)->refusal($me, request()->ip(), SignInGate::deviceOf(request()), SignInGate::tokenOf(request())) !== null) {
                throw ValidationException::withMessages(['signInNetwork' => 'That would sign you out from where you are now. Choose a rule that lets you in here.']);
            }
        }

        if ($network === NetworkRule::Office && SignInRules::current()->office === []) {
            throw ValidationException::withMessages(['signInNetwork' => 'Add the office\'s internet address on Sign-in security first.']);
        }

        return [$network, $phones];
    }

    private function create(): void
    {
        /** @var User $actor */
        $actor = auth()->user();
        $isAdmin = in_array($this->kind, self::GLOBAL_KINDS, true);
        $strong = in_array($this->kind, ['admin', 'it'], true);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'designation' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', $strong ? Password::min(12)->letters()->numbers() : Password::min(10)->letters()->numbers()],
            'kind' => ['required', Rule::in(self::KINDS)],
            'brandId' => [$isAdmin ? 'nullable' : 'required', 'integer'],
            'departmentId' => ['nullable', 'integer'],
            'targetRevenue' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'targetCount' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], attributes: ['brandId' => 'brand', 'departmentId' => 'department', 'targetRevenue' => 'revenue target', 'targetCount' => 'sales target']);

        [$network, $phones] = SignInRules::ready() ? $this->chosenSignInRules() : [null, null];
        $email = mb_strtolower(trim($data['email']));
        $existing = User::query()->where('email', $email)->first();

        if ($isAdmin) {
            $role = GlobalRole::from($data['kind']);
            abort_unless($actor->can('assignGlobalRole', [User::class, $role]), 403);

            if ($existing !== null) {
                throw ValidationException::withMessages(['email' => 'This email address already has an account.']);
            }

            $leadBrands = $role === GlobalRole::WebsiteLeads ? $this->chosenLeadBrands() : [];

            $person = DB::transaction(function () use ($data, $email, $role, $actor, $leadBrands): User {
                $person = User::query()->create([
                    'name' => $data['name'], 'email' => $email, 'password' => $data['password'],
                    'designation' => $data['designation'] ?: null, 'phone' => $data['phone'] ?: null,
                    'role' => $role, 'created_by' => $actor->getKey(),
                ]);
                $person->forceFill(['email_verified_at' => now()])->save();

                if ($role === GlobalRole::WebsiteLeads) {
                    WebsiteLeads::choose($person, $leadBrands);
                }

                return $person;
            });

            if (SignInRules::ready()) {
                app(ChangeSignInRules::class)->forPerson($person, $network, $phones);
            }

            Audit::log('members.admin_created', $person, ['email' => $person->email, 'role' => $role->value], $role->getLabel().' account created', logName: 'members');
            $this->toastSuccess($person->name.' added as '.$role->getLabel(), $person->requiresTwoFactor() ? 'They will be asked to set up an authenticator app on first sign-in.' : null);
            $this->redirectRoute('admin.people.index', navigate: true);

            return;
        }

        if ($existing !== null && $existing->role !== null) {
            throw ValidationException::withMessages(['email' => $existing->isGlobalAdmin() ? 'This person is an Admin and already sees every brand.' : 'This email address is a '.$existing->role->getLabel().' account, which works outside the brands.']);
        }

        $brand = Brand::query()->findOrFail((int) $data['brandId']);
        $department = filled($data['departmentId']) ? Department::query()->forBrand($brand)->findOrFail((int) $data['departmentId']) : null;
        $role = BrandRole::from($data['kind']);

        $person = DB::transaction(function () use ($data, $email, $existing, $brand, $department, $role, $actor, $network, $phones): User {
            $person = $existing ?? User::query()->create([
                'name' => $data['name'], 'email' => $email, 'password' => $data['password'],
                'designation' => $data['designation'] ?: null, 'phone' => $data['phone'] ?: null,
                'created_by' => $actor->getKey(),
            ]);

            if ($existing === null) {
                $person->forceFill(['email_verified_at' => now()])->save();
            }

            // An existing account keeps the rules it has unless some were chosen here.
            if (SignInRules::ready() && ($existing === null || $network !== null || $phones !== null)) {
                app(ChangeSignInRules::class)->forPerson($person, $network, $phones);
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
            'password' => ['nullable', 'string', Password::min($account->isGlobalAdmin() || $account->isIt() ? 12 : 10)->letters()->numbers()],
        ]);

        $leadBrands = $account->isWebsiteLeads() ? $this->chosenLeadBrands() : null;

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

        [$network, $phones] = SignInRules::ready() ? $this->chosenSignInRules() : [null, null];

        $account->forceFill($attributes)->save();

        if ($leadBrands !== null) {
            WebsiteLeads::choose($account, $leadBrands);
        }

        if (SignInRules::ready()) {
            app(ChangeSignInRules::class)->forPerson($account, $network, $phones);
        }

        Audit::log($account->role !== null ? 'members.admin_updated' : 'members.profile_updated', $account, ['email' => $account->email] + ($leadBrands !== null ? ['website_lead_brands' => $leadBrands] : []), 'Account updated', logName: 'members');
        $this->toastSuccess('Account saved');
        $this->redirectRoute('admin.people.index', navigate: true);
    }

    /**
     * The brands ticked for a Website leads person, checked to be brands that exist.
     *
     * @return list<int>
     */
    private function chosenLeadBrands(): array
    {
        $this->validate([
            'leadBrandIds' => ['required', 'array', 'min:1'],
            'leadBrandIds.*' => ['integer', Rule::exists('brands', 'id')],
        ], ['leadBrandIds.required' => 'Choose at least one brand.', 'leadBrandIds.min' => 'Choose at least one brand.'], ['leadBrandIds' => 'brands']);

        return array_map(intval(...), array_values($this->leadBrandIds));
    }

    /** A Super Admin's role and status, and your own, cannot be changed from this form. */
    private function locked(): bool
    {
        return $this->account !== null && ($this->account->isSuperAdmin() || $this->account->is(auth()->user()));
    }

    /** The Manager or Agent being edited, when the actor may share brands with them. */
    private function sharedAccount(): User
    {
        $account = $this->account ?? abort(404);
        $this->authorize('shareBrands', $account);

        return $account;
    }

    private function sharedMembership(int $membershipId): Membership
    {
        $person = $this->sharedAccount();

        return Membership::query()->acrossBrands()->with('brand')->where('user_id', $person->getKey())->findOrFail($membershipId);
    }

    private function actor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }

    /**
     * Each brand the person works in, with their role, their departments
     * there and the departments they could move to.
     *
     * @return list<array{membership: Membership, brand: Brand, departments: array<int, string>, current: list<int>}>
     */
    private function sharedBrands(): array
    {
        if ($this->account === null || $this->account->role !== null) {
            return [];
        }

        $memberships = Membership::query()->acrossBrands()->where('user_id', $this->account->getKey())->with('brand')->get()
            ->filter(fn (Membership $membership): bool => $membership->brand !== null)
            ->sortBy(fn (Membership $membership): string => (string) $membership->brand?->name);

        $departments = Department::query()->acrossBrands()->whereIn('brand_id', $memberships->pluck('brand_id'))->orderBy('name')->get(['id', 'brand_id', 'name'])->groupBy('brand_id');
        $seats = $this->account->departments()->acrossBrands()->pluck('departments.id')->map(fn ($id): int => (int) $id)->all();

        return $memberships->map(function (Membership $membership) use ($departments, $seats): array {
            $options = ($departments[$membership->brand_id] ?? collect())->pluck('name', 'id')->all();

            /** @var Brand $brand */
            $brand = $membership->brand;

            return [
                'membership' => $membership,
                'brand' => $brand,
                'departments' => $options,
                'current' => array_values(array_intersect($seats, array_map('intval', array_keys($options)))),
            ];
        })->values()->all();
    }
}
