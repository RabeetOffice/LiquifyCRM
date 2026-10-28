<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Database\Factories\UserFactory;
use App\Modules\Auth\Exceptions\ProtectedUserException;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Models\PushSubscription;
use App\Modules\Core\Support\WebPush;
use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Models\DepartmentUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string|null $designation
 * @property string $email
 * @property string $password
 * @property GlobalRole|null $role
 * @property bool $is_protected
 * @property MembershipStatus $status
 * @property string|null $phone
 * @property string|null $avatar_path
 * @property string|null $timezone
 * @property string|null $sign_in_network NetworkRule value, null = the rule for everybody
 * @property string|null $sign_in_phones PhoneRule value, null = the rule for everybody
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $last_login_ip
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property int|null $created_by
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'name', 'designation', 'email', 'password', 'role', 'is_protected', 'status',
        'phone', 'avatar_path', 'timezone', 'created_by',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => GlobalRole::class,
            'is_protected' => 'boolean',
            'status' => MembershipStatus::class,
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected static function booted(): void
    {
        // The last Super Admin can never be removed or demoted, whoever asks.
        static::saving(function (self $user): void {
            if ($user->isSuperAdmin()) {
                $user->is_protected = true;
            }

            if (! $user->exists || ! $user->isDirty('role')) {
                return;
            }

            $wasSuperAdmin = $user->getRawOriginal('role') === GlobalRole::SuperAdmin->value;

            if ($wasSuperAdmin && ! $user->isSuperAdmin() && static::superAdmins()->count() <= 1) {
                throw ProtectedUserException::lastSuperAdmin();
            }
        });

        // A replaced password signs the person out everywhere, whichever screen replaced it (security audit,
        // 28 Oct 2026): People › Edit, the emailed reset, the authenticator reset or their own profile. Only the
        // browser that changed its own password stays signed in.
        static::saving(function (self $user): void {
            if ($user->exists && $user->isDirty('password') && ! $user->isDirty('remember_token')) {
                $user->setRememberToken(Str::random(60));
            }
        });

        static::updated(function (self $user): void {
            if (! $user->wasChanged('password')) {
                return;
            }

            $self = Auth::id() === $user->getKey() && app()->bound('session.store');

            // Desktop alerts go to a browser, not a session, so they stop too (except on the browser that changed it).
            if (WebPush::ready()) {
                $endpoint = $self ? app('session.store')->get('push_endpoint') : null;

                PushSubscription::query()->where('user_id', $user->getKey())
                    ->when(is_string($endpoint), fn ($query) => $query->where('endpoint_hash', '!=', $endpoint))
                    ->delete();
            }

            if (config('session.driver') !== 'database') {
                return;
            }

            $keep = $self ? app('session.store')->getId() : null;

            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($keep !== null, fn ($query) => $query->where('id', '!=', $keep))
                ->delete();
        });

        static::deleting(function (self $user): void {
            if ($user->isSuperAdmin() && static::superAdmins()->count() <= 1) {
                throw ProtectedUserException::lastSuperAdmin();
            }
        });
    }

    // ------------------------------------------------------------------ roles

    public function isSuperAdmin(): bool
    {
        return $this->role === GlobalRole::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === GlobalRole::Admin;
    }

    /** Super Admin or Admin: sees every brand. */
    public function isGlobalAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin();
    }

    /** IT: People, passwords, two-step and the System screens; never a brand, a lead or a sale. */
    public function isIt(): bool
    {
        return $this->role === GlobalRole::It;
    }

    /** Website leads: reads the website leads of the brands chosen for them (WebsiteLeads), nothing else. */
    public function isWebsiteLeads(): bool
    {
        return $this->role === GlobalRole::WebsiteLeads;
    }

    /** People, Sign-in security, Logs, Updates and System: Admins and IT. */
    public function canManageSystem(): bool
    {
        return $this->isActive() && ($this->isGlobalAdmin() || $this->isIt());
    }

    /**
     * Where IT and Website leads people land, since they open no brand: their
     * own screen. Null for everybody else (a brand, or the admin area).
     */
    public function homeUrl(): ?string
    {
        return match (true) {
            $this->isIt() => route('admin.people.index'),
            $this->isWebsiteLeads() => route('website-leads'),
            default => null,
        };
    }

    public function isSuspended(): bool
    {
        return $this->status === MembershipStatus::Suspended;
    }

    public function isActive(): bool
    {
        return ! $this->isSuspended();
    }

    /** @return Builder<static> */
    public static function superAdmins(): Builder
    {
        return static::query()->where('role', GlobalRole::SuperAdmin->value);
    }

    // ------------------------------------------------------------- memberships

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return BelongsToMany<Brand, $this, Membership> */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_user')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'status', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    public function membershipFor(Brand|int $brand): ?Membership
    {
        $brandId = $brand instanceof Brand ? $brand->getKey() : $brand;

        if ($this->relationLoaded('memberships')) {
            return $this->memberships->firstWhere('brand_id', $brandId);
        }

        return $this->memberships()->acrossBrands()->where('brand_id', $brandId)->first();
    }

    public function brandRole(Brand|int $brand): ?BrandRole
    {
        return $this->membershipFor($brand)?->role;
    }

    public function isMemberOf(Brand|int $brand): bool
    {
        if ($this->isGlobalAdmin()) {
            return true;
        }

        $membership = $this->membershipFor($brand);

        return $membership !== null && $membership->status === MembershipStatus::Active;
    }

    public function isManagerIn(Brand|int $brand): bool
    {
        return $this->brandRole($brand) === BrandRole::Manager;
    }

    public function isAgentIn(Brand|int $brand): bool
    {
        return $this->brandRole($brand) === BrandRole::Agent;
    }

    /**
     * Plain-words role for the sidebar and profile: the role in that brand,
     * or, outside a brand, every role the person has ("Manager and Agent"
     * for somebody who is a Manager in one brand and an Agent in another).
     */
    public function roleLabelIn(?Brand $brand = null): string
    {
        return match (true) {
            $this->isSuperAdmin() => 'Super Admin',
            $this->isAdmin() => 'Administrator',
            $this->isIt() => 'IT',
            $this->isWebsiteLeads() => 'Website leads',
            $brand !== null => $this->brandRole($brand)?->getLabel() ?? 'Member',
            default => $this->brandRolesLabel(),
        };
    }

    private function brandRolesLabel(): string
    {
        $roles = $this->memberships()->acrossBrands()->where('status', MembershipStatus::Active->value)->pluck('role')
            ->map(fn (mixed $role): string => $role instanceof BrandRole ? $role->value : (string) $role);

        $labels = collect(BrandRole::cases())->filter(fn (BrandRole $role): bool => $roles->contains($role->value))->map(fn (BrandRole $role): string => $role->getLabel());

        return $labels->isEmpty() ? 'Member' : $labels->join(', ', ' and ');
    }

    // ------------------------------------------------------------- departments

    /** @return BelongsToMany<Department, $this, DepartmentUser> */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user')
            ->using(DepartmentUser::class)
            ->withPivot(['id', 'role', 'created_by'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Department, $this, DepartmentUser> */
    public function managedDepartments(): BelongsToMany
    {
        return $this->departments()->wherePivot('role', DepartmentRole::Manager->value);
    }

    /**
     * Ids of the departments this user manages inside one brand.
     *
     * @return list<int>
     */
    public function managedDepartmentIds(Brand|int $brand): array
    {
        $brandId = $brand instanceof Brand ? $brand->getKey() : $brand;

        return $this->managedDepartments()
            ->acrossBrands()
            ->where('departments.brand_id', $brandId)
            ->pluck('departments.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Ids of every department (managed or worked in) inside one brand.
     *
     * @return list<int>
     */
    public function departmentIds(Brand|int $brand): array
    {
        $brandId = $brand instanceof Brand ? $brand->getKey() : $brand;

        return $this->departments()
            ->acrossBrands()
            ->where('departments.brand_id', $brandId)
            ->pluck('departments.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Ids of the brand's front sales teams this user is on (managed or worked
     * in): every department that works leads, not the Upsell ones. Front
     * sales sees the whole team's leads, so whoever is free can take one.
     *
     * @return list<int>
     */
    public function frontDepartmentIds(Brand|int $brand): array
    {
        $brandId = $brand instanceof Brand ? $brand->getKey() : $brand;

        return $this->departments()
            ->acrossBrands()
            ->where('departments.brand_id', $brandId)
            ->get()
            ->reject(fn (Department $department): bool => $department->isUpsell())
            ->map(fn (Department $department): int => (int) $department->getKey())
            ->values()
            ->all();
    }

    public function managesDepartment(Department|int $department): bool
    {
        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        return $this->managedDepartments()->acrossBrands()->where('departments.id', $departmentId)->exists();
    }

    public function belongsToDepartment(Department|int $department): bool
    {
        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        return $this->departments()->acrossBrands()->where('departments.id', $departmentId)->exists();
    }

    // ----------------------------------------------------------------- access

    /** The admin area is for Super Admins and Admins who are not suspended. */
    public function canAccessAdminArea(): bool
    {
        return $this->isActive() && $this->isGlobalAdmin();
    }

    /**
     * Brands this person may open: every active brand for global admins,
     * their active memberships otherwise.
     *
     * @return Collection<int, Brand>
     */
    public function accessibleBrands(): Collection
    {
        if ($this->isGlobalAdmin()) {
            return Brand::query()->where('is_active', true)->orderBy('name')->get();
        }

        return $this->brands()
            ->wherePivot('status', MembershipStatus::Active->value)
            ->where('brands.is_active', true)
            ->orderBy('brands.name')
            ->get();
    }

    public function canAccessBrand(Brand $brand): bool
    {
        if (! $brand->is_active || $this->isSuspended()) {
            return false;
        }

        if ($this->isGlobalAdmin()) {
            return true;
        }

        // IT and Website leads people never open a brand, even with a membership left over.
        if ($this->isIt() || $this->isWebsiteLeads()) {
            return false;
        }

        return $this->memberships()
            ->acrossBrands()
            ->where('brand_id', $brand->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }

    public function defaultBrand(): ?Brand
    {
        $brand = $this->accessibleBrands()->first();

        return $brand instanceof Brand ? $brand : null;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    // --------------------------------------------------------- two-factor auth

    /** Super Admins, Admins and IT must set up an authenticator app (unless switched off by LIQUIFY_REQUIRE_TWO_FACTOR). */
    public function requiresTwoFactor(): bool
    {
        return ($this->isGlobalAdmin() || $this->isIt()) && (bool) config('liquify.require_two_factor');
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->hasEnabledTwoFactorAuthentication();
    }
}
