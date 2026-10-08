<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire\Admin;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin accounts (Super Admins and Admins). Super Admin only: an Admin
 * manages Managers and Agents inside brands, never other admins.
 */
#[Layout('components.layouts.app')]
#[Title('Admin accounts')]
final class AdminAccountsIndex extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithTableState;
    use WithToasts;

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
        $user = User::query()->findOrFail($userId);
        $this->authorize('delete', $user);

        $user->delete();

        Audit::log('members.removed', $user, ['email' => $user->email, 'role' => $user->role?->value], 'Removed '.$user->name, logName: 'members');

        $this->toastSuccess($user->name.' removed');
    }

    public function render(): View
    {
        $like = '%'.$this->search.'%';

        $admins = User::query()
            ->whereIn('role', [GlobalRole::SuperAdmin->value, GlobalRole::Admin->value])
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->orderBy($this->sortColumn(['name', 'email', 'role', 'last_login_at', 'created_at'], 'name'), $this->sortDirection())
            ->paginate($this->perPage);

        return view('auth::livewire.admin.admin-accounts-index', ['admins' => $admins]);
    }
}
