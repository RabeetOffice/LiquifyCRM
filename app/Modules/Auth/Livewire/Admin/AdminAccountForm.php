<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire\Admin;

use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Create or edit a Super Admin or Admin account. */
#[Layout('components.layouts.app')]
final class AdminAccountForm extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    public ?User $account = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'admin';

    public string $status = 'active';

    public string $password = '';

    public function mount(?int $userId = null): void
    {
        if ($userId !== null) {
            $this->account = User::query()->findOrFail($userId);
            $this->authorize('update', $this->account);

            $this->name = $this->account->name;
            $this->email = $this->account->email;
            $this->role = $this->account->role->value ?? 'admin';
            $this->status = $this->account->status->value;
        } else {
            $this->authorize('create', User::class);
            abort_unless(auth()->user()?->can('assignGlobalRole', [User::class, GlobalRole::Admin]), 403);
        }
    }

    public function save(): void
    {
        $locked = $this->account !== null && ($this->account->isSuperAdmin() || $this->account->is(auth()->user()));

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->account?->getKey())],
            'role' => ['required', Rule::in(array_column(GlobalRole::cases(), 'value'))],
            'status' => ['required', Rule::in(array_column(MembershipStatus::cases(), 'value'))],
            'password' => [$this->account === null ? 'required' : 'nullable', 'string', Password::min(12)->letters()->numbers()],
        ]);

        $role = GlobalRole::from($data['role']);

        abort_unless(auth()->user()?->can('assignGlobalRole', [User::class, $role]), 403);

        $attributes = [
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
        ];

        if (! $locked) {
            $attributes['role'] = $role;
            $attributes['status'] = MembershipStatus::from($data['status']);
        }

        if (filled($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        if ($this->account === null) {
            $account = User::query()->create([...$attributes, 'created_by' => auth()->id()]);
            $account->forceFill(['email_verified_at' => now()])->save();

            Audit::log('members.admin_created', $account, ['email' => $account->email, 'role' => $role->value], 'Admin account created', logName: 'members');
            $this->toastSuccess($account->name.' added', 'They will be asked to set up an authenticator app on first sign-in.');
        } else {
            $this->account->forceFill($attributes)->save();

            Audit::log('members.admin_updated', $this->account, ['email' => $this->account->email], 'Admin account updated', logName: 'members');
            $this->toastSuccess('Account saved');
        }

        $this->redirectRoute('admin.admins.index', navigate: true);
    }

    public function render(): View
    {
        return view('auth::livewire.admin.admin-account-form', [
            'title' => $this->account ? 'Edit '.$this->account->name : 'Add admin',
            'locked' => $this->account !== null && ($this->account->isSuperAdmin() || $this->account->is(auth()->user())),
            'roles' => GlobalRole::cases(),
            'statuses' => MembershipStatus::cases(),
        ])->title($this->account ? 'Edit admin' : 'Add admin');
    }
}
