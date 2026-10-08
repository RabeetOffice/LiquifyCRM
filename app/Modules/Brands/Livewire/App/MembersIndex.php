<?php

declare(strict_types=1);

namespace App\Modules\Brands\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\InviteMember;
use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Brands\Models\Membership;
use App\Modules\Brands\Notifications\InvitationNotification;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Enums\DepartmentRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Members screen: who has access to this brand and with which role.
 * Admins see everyone; a Manager sees the Agents of their departments.
 */
#[Layout('components.layouts.app')]
#[Title('Members')]
final class MembersIndex extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithTableState;
    use WithToasts;

    public Brand $brand;

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $status = '';

    // Invite modal
    public bool $showInvite = false;

    public string $inviteEmail = '';

    public string $inviteName = '';

    public string $inviteRole = 'agent';

    public string $inviteDepartment = '';

    // Edit modal
    public ?int $editing = null;

    public string $editRole = 'agent';

    public string $editStatus = 'active';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Membership::class);
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    // ---------------------------------------------------------------- invite

    public function openInvite(): void
    {
        $this->authorize('create', Membership::class);
        $this->reset('inviteEmail', 'inviteName', 'inviteDepartment');
        $this->inviteRole = 'agent';
        $this->resetErrorBag();
        $this->showInvite = true;
    }

    public function sendInvite(): void
    {
        $this->authorize('create', Membership::class);

        $data = $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteName' => ['nullable', 'string', 'max:255'],
            'inviteRole' => ['required', Rule::in(array_keys($this->roleOptions()))],
            'inviteDepartment' => [$this->user()->isGlobalAdmin() ? 'nullable' : 'required', 'integer'],
        ]);

        $role = BrandRole::from($data['inviteRole']);
        $department = filled($data['inviteDepartment']) ? Department::query()->findOrFail((int) $data['inviteDepartment']) : null;

        $this->guardRoleChoice($role, $department);

        app(InviteMember::class)->handle(
            $this->brand,
            $data['inviteEmail'],
            $role,
            $department,
            $department ? DepartmentRole::from($role === BrandRole::Manager ? 'manager' : 'agent') : null,
            $data['inviteName'] ?: null,
            $this->user(),
        );

        $this->showInvite = false;
        $this->toastSuccess('Invitation sent to '.$data['inviteEmail']);
    }

    public function resendInvitation(int $invitationId): void
    {
        $invitation = Invitation::query()->pending()->findOrFail($invitationId);
        $this->authorize('viewAny', Invitation::class);

        $invitation->forceFill(['expires_at' => now()->addDays((int) config('liquify.invitation_expiry_days'))])->save();

        Notification::route('mail', $invitation->email)->notify(new InvitationNotification($invitation));

        Audit::log('members.invite_resent', $invitation, ['email' => $invitation->email], 'Invitation resent to '.$invitation->email, logName: 'members');

        $this->toastSuccess('Invitation sent again');
    }

    public function revokeInvitation(int $invitationId): void
    {
        $invitation = Invitation::query()->pending()->findOrFail($invitationId);
        $this->authorize('delete', $invitation);

        $invitation->delete();

        Audit::log('members.invite_revoked', $invitation, ['email' => $invitation->email], 'Invitation revoked for '.$invitation->email, logName: 'members');

        $this->toastSuccess('Invitation revoked');
    }

    // ------------------------------------------------------------------ edit

    public function openEdit(int $membershipId): void
    {
        $membership = $this->membershipQuery()->findOrFail($membershipId);
        $this->authorize('update', $membership);

        $this->editing = $membershipId;
        $this->editRole = $membership->role->value;
        $this->editStatus = $membership->status->value;
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $membership = $this->membershipQuery()->findOrFail((int) $this->editing);
        $this->authorize('update', $membership);

        $data = $this->validate([
            'editRole' => ['required', Rule::in(array_column(BrandRole::assignable(), 'value'))],
            'editStatus' => ['required', Rule::in(array_column(MembershipStatus::cases(), 'value'))],
        ]);

        $changes = ['status' => MembershipStatus::from($data['editStatus'])];

        if ($this->user()->isGlobalAdmin()) {
            $changes['role'] = BrandRole::from($data['editRole']);
        }

        $membership->forceFill($changes)->save();

        Audit::log('members.updated', $membership, ['user_id' => $membership->user_id, 'role' => $membership->role->value, 'status' => $membership->status->value], 'Access updated for '.($membership->user->name ?? ''), logName: 'members');

        $this->editing = null;
        $this->toastSuccess('Access saved');
    }

    public function confirmToggle(int $membershipId): void
    {
        $membership = $this->membershipQuery()->findOrFail($membershipId);
        $this->authorize('update', $membership);

        $suspending = $membership->isActive();
        $name = $membership->user->name ?? 'this person';

        $this->askConfirmation('toggleStatus', [$membershipId], ($suspending ? 'Suspend ' : 'Reactivate ').$name.'?', $suspending ? 'They cannot sign in to this brand until reactivated.' : 'They can sign in to this brand again.', $suspending ? 'Suspend' : 'Reactivate', $suspending ? 'danger' : 'primary');
    }

    public function toggleStatus(int $membershipId): void
    {
        $membership = $this->membershipQuery()->findOrFail($membershipId);
        $this->authorize('update', $membership);

        $suspending = $membership->isActive();
        $membership->forceFill(['status' => $suspending ? MembershipStatus::Suspended : MembershipStatus::Active])->save();

        Audit::log($suspending ? 'members.suspended' : 'members.reactivated', $membership, ['user_id' => $membership->user_id], ($suspending ? 'Suspended ' : 'Reactivated ').($membership->user->name ?? ''), logName: 'members');

        $this->toastSuccess($suspending ? 'Access suspended' : 'Access restored');
    }

    public function confirmRemove(int $membershipId): void
    {
        $membership = $this->membershipQuery()->findOrFail($membershipId);
        $this->authorize('delete', $membership);

        $this->askConfirmation('remove', [$membershipId], 'Remove '.($membership->user->name ?? 'this person').' from this brand?', 'Their access ends immediately and their open leads go back to the queue. Their account stays so history is kept.', 'Remove', 'danger', password: true);
    }

    public function remove(int $membershipId): void
    {
        $membership = $this->membershipQuery()->findOrFail($membershipId);
        $this->authorize('delete', $membership);

        $name = $membership->user->name ?? 'Member';

        app(RemoveMemberFromBrand::class)->handle($membership, $this->user());

        $this->toastSuccess($name.' removed');
    }

    // ---------------------------------------------------------------- render

    public function render(): View
    {
        $like = '%'.$this->search.'%';

        $members = $this->membershipQuery()
            ->when($this->search !== '', fn (Builder $query): Builder => $query->whereHas('user', fn (Builder $user): Builder => $user->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->when($this->role !== '', fn (Builder $query): Builder => $query->where('role', $this->role))
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            ->orderBy($this->sortColumn(['role', 'status', 'joined_at'], 'joined_at'), $this->sortDirection('desc'))
            ->paginate($this->perPage);

        $departmentsByUser = Department::query()
            ->join('department_user', 'department_user.department_id', '=', 'departments.id')
            ->whereIn('department_user.user_id', $members->pluck('user_id'))
            ->orderBy('departments.name')
            ->get(['departments.name', 'department_user.user_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('name')->all());

        $invitations = $this->user()->can('viewAny', Invitation::class)
            ? Invitation::query()->pending()->with(['department', 'inviter'])->when(! $this->user()->isGlobalAdmin(), fn (Builder $query): Builder => $query->where('invited_by', $this->user()->getKey()))->latest('id')->get()
            : collect();

        $editing = $this->editing ? $this->membershipQuery()->find($this->editing) : null;

        return view('brands::livewire.app.members-index', [
            'members' => $members,
            'departmentsByUser' => $departmentsByUser,
            'invitations' => $invitations,
            'editingMembership' => $editing,
            'roleOptions' => $this->roleOptions(),
            'allRoleOptions' => collect(BrandRole::assignable())->mapWithKeys(fn (BrandRole $role): array => [$role->value => $role->getLabel()])->all(),
            'statusOptions' => collect(MembershipStatus::cases())->mapWithKeys(fn (MembershipStatus $status): array => [$status->value => $status->getLabel()])->all(),
            'departmentOptions' => $this->departmentOptions(),
            'canInvite' => $this->user()->can('create', Membership::class),
        ]);
    }

    /** @return Builder<Membership> */
    private function membershipQuery(): Builder
    {
        $query = Membership::query()->with(['user', 'inviter']);
        $user = $this->user();

        if ($user->isGlobalAdmin()) {
            return $query;
        }

        $managed = $user->managedDepartmentIds($this->brand);

        return $query
            ->where('role', BrandRole::Agent->value)
            ->whereHas('user.departments', fn (Builder $departments): Builder => $departments->withoutGlobalScopes()->whereIn('departments.id', $managed));
    }

    /** @return array<string, string> */
    private function roleOptions(): array
    {
        $roles = $this->user()->isGlobalAdmin() ? BrandRole::assignable() : [BrandRole::Agent];

        return collect($roles)->mapWithKeys(fn (BrandRole $role): array => [$role->value => $role->getLabel()])->all();
    }

    /** @return array<int, string> */
    private function departmentOptions(): array
    {
        $user = $this->user();
        $query = Department::query()->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereIn('id', $user->managedDepartmentIds($this->brand));
        }

        return $query->pluck('name', 'id')->all();
    }

    /** Managers may only add Agents, and only into departments they manage. */
    private function guardRoleChoice(BrandRole $role, ?Department $department): void
    {
        $user = $this->user();

        if ($user->isGlobalAdmin()) {
            return;
        }

        abort_if($role !== BrandRole::Agent, 403, 'Managers can only add Agents.');
        abort_if($department === null || ! $user->managesDepartment($department), 403, 'Choose one of your own departments.');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
