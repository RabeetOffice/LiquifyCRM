<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire\Admin;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everyone who can sign in, across all brands: Super Admins, Admins,
 * Managers and Agents. Super Admins and Admins add and manage them here; a
 * Super Admin is protected and can only be changed by themselves.
 */
#[Layout('components.layouts.app')]
#[Title('People')]
final class PeopleIndex extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithTableState;
    use WithToasts;

    /** all | admins | managers | agents */
    #[Url(except: 'all')]
    public string $tab = 'all';

    #[Url(as: 'brand', except: '')]
    public string $brandFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedBrandFilter(): void
    {
        $this->resetPage();
    }

    public function confirmToggle(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('suspend', $user);

        $suspending = ! $user->isSuspended();

        $this->askConfirmation('toggleStatus', [$userId], ($suspending ? 'Suspend ' : 'Reactivate ').$user->name.'?', $suspending ? 'They cannot sign in until reactivated.' : 'They can sign in again.', $suspending ? 'Suspend' : 'Reactivate', $suspending ? 'danger' : 'primary');
    }

    public function toggleStatus(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('suspend', $user);

        $suspending = ! $user->isSuspended();
        $user->forceFill(['status' => $suspending ? MembershipStatus::Suspended : MembershipStatus::Active])->save();

        Audit::log($suspending ? 'members.suspended' : 'members.reactivated', $user, ['email' => $user->email], ($suspending ? 'Suspended ' : 'Reactivated ').$user->name, logName: 'members');

        $this->toastSuccess($suspending ? 'Account suspended' : 'Account reactivated');
    }

    public function confirmDelete(int $userId): void
    {
        $user = User::query()->findOrFail($userId);
        $this->authorize('delete', $user);

        $this->askConfirmation('delete', [$userId], 'Remove '.$user->name.'?', 'Their access ends immediately. The account is kept so history stays readable.', 'Remove', 'danger', password: true);
    }

    public function delete(int $userId): void
    {
        $this->ensureConfirmed();

        $user = User::query()->findOrFail($userId);
        $this->authorize('delete', $user);

        $user->delete();

        Audit::log('members.removed', $user, ['email' => $user->email, 'role' => $user->role?->value], 'Removed '.$user->name, logName: 'members');

        $this->toastSuccess($user->name.' removed');
    }

    public function render(): View
    {
        $like = '%'.$this->search.'%';
        $brandId = ctype_digit($this->brandFilter) ? (int) $this->brandFilter : null;
        $globalRoles = [GlobalRole::SuperAdmin->value, GlobalRole::Admin->value];

        $inRole = fn (BrandRole $role): callable => fn (Builder $query): Builder => $query
            ->withoutGlobalScopes()
            ->where('role', $role->value)
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId));

        $people = User::query()
            ->with(['memberships' => function (Relation $query): void {
                $query->withoutGlobalScopes()->with('brand');
            }])
            ->when($this->tab === 'admins', fn (Builder $query): Builder => $query->whereIn('role', $globalRoles))
            ->when($this->tab === 'managers', fn (Builder $query): Builder => $query->whereHas('memberships', $inRole(BrandRole::Manager)))
            ->when($this->tab === 'agents', fn (Builder $query): Builder => $query->whereHas('memberships', $inRole(BrandRole::Agent)))
            ->when($brandId !== null && ! in_array($this->tab, ['managers', 'agents'], true), fn (Builder $query): Builder => $query->whereHas('memberships', fn (Builder $q): Builder => $q->withoutGlobalScopes()->where('brand_id', $brandId)))
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('designation', 'like', $like)))
            ->orderBy($this->sortColumn(['name', 'email', 'last_login_at', 'created_at'], 'name'), $this->sortDirection())
            ->paginate($this->perPage);

        return view('auth::livewire.admin.people-index', [
            'people' => $people,
            'brands' => Brand::query()->orderBy('name')->pluck('name', 'id')->all(),
            'counts' => [
                'all' => User::query()->count(),
                'admins' => User::query()->whereIn('role', $globalRoles)->count(),
                'managers' => User::query()->whereHas('memberships', fn (Builder $q): Builder => $q->withoutGlobalScopes()->where('role', BrandRole::Manager->value))->count(),
                'agents' => User::query()->whereHas('memberships', fn (Builder $q): Builder => $q->withoutGlobalScopes()->where('role', BrandRole::Agent->value))->count(),
            ],
        ]);
    }
}
