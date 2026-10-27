<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire\Admin;

use App\Modules\Auth\Actions\ManageTwoStep;
use App\Modules\Auth\Models\User;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everybody's two-step codes in one place (user request, 27 Oct 2026): an
 * Admin or IT turns two-step on for people from here instead of on each
 * computer, sees the code each person's app shows right now (they change
 * every 30 seconds) and the QR code to add that person to an authenticator
 * app such as Google Authenticator. Every person keeps a key of their own.
 *
 * The panel opens only after the viewer's password, for ten minutes on that
 * browser (kept in the session, never on the page). IT sees Managers, Agents
 * and Website leads people; Admins everybody but Super Admins
 * (UserPolicy::manageTwoStep). Opening it and every QR shown are logged.
 */
#[Layout('components.layouts.app')]
#[Title('Two-step codes')]
final class TwoStepPanel extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithTableState;
    use WithToasts;

    private const UNLOCKED = 'two_step_panel_until';

    private const MINUTES = 10;

    /** all | on | off */
    #[Url(except: 'all')]
    public string $tab = 'all';

    /** The person whose QR code is open; chosen on the server after the check. */
    #[Locked]
    public ?int $qrFor = null;

    public bool $showQr = false;

    public function mount(): void
    {
        abort_unless($this->viewer()->canManageSystem(), 403);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function unlock(): void
    {
        $this->askConfirmation('doUnlock', [], 'Open the two-step codes', 'Confirm your password. The codes stay open on this browser for '.self::MINUTES.' minutes.', 'Open', 'primary', password: true);
    }

    public function doUnlock(): void
    {
        $this->ensureConfirmed();

        session()->put(self::UNLOCKED, now()->addMinutes(self::MINUTES)->getTimestamp());
        Audit::log('auth.two_step_panel_opened', null, [], 'Two-step codes opened', logName: 'auth');
    }

    public function lock(): void
    {
        session()->forget(self::UNLOCKED);
        $this->closeQr();
    }

    public function turnOn(int $userId, ManageTwoStep $twoStep): void
    {
        $person = $this->person($userId);
        abort_if($person->hasTwoFactorEnabled(), 409, 'Two-step is already on.');

        $twoStep->turnOn($person, $this->viewer());
        $this->openQr($person);
        $this->toastSuccess('Two-step is on for '.$person->name, 'Scan the QR code into their app, or into yours to read their codes.');
    }

    public function confirmTurnOnForEveryone(): void
    {
        $this->ensureUnlocked();
        $count = $this->withoutTwoStep()->count();

        abort_if($count === 0, 409, 'Everybody already has two-step.');

        $this->askConfirmation('turnOnForEveryone', [], 'Turn two-step on for '.$count.' '.($count === 1 ? 'person' : 'people').'?', 'Each gets a key of their own. From their next sign-in they need the 6-digit code: from you, or from their app once they scan their QR code.', 'Turn on', 'primary');
    }

    public function turnOnForEveryone(ManageTwoStep $twoStep): void
    {
        $this->ensureConfirmed();
        $this->ensureUnlocked();

        $count = 0;

        foreach ($this->withoutTwoStep()->get() as $person) {
            if ($this->viewer()->can('manageTwoStep', $person)) {
                $twoStep->turnOn($person, $this->viewer());
                $count++;
            }
        }

        $this->toastSuccess('Two-step is on for '.$count.' '.($count === 1 ? 'person' : 'people'));
    }

    public function confirmRenew(int $userId): void
    {
        $person = $this->person($userId);

        $this->askConfirmation('renew', [$userId], 'Give '.$person->name.' a new key?', 'The code in their app stops working at once. Scan the new QR code into their app (and yours) afterwards.', 'New key', 'danger');
    }

    public function renew(int $userId, ManageTwoStep $twoStep): void
    {
        $this->ensureConfirmed();
        $person = $this->person($userId);

        $twoStep->turnOn($person, $this->viewer());
        $this->openQr($person);
        $this->toastSuccess('New key for '.$person->name);
    }

    public function confirmTurnOff(int $userId): void
    {
        $person = $this->person($userId);

        $this->askConfirmation('turnOff', [$userId], 'Turn off two-step for '.$person->name.'?', $person->requiresTwoFactor() ? 'Their role needs two-step, so they will be asked to set it up again when they sign in.' : 'They will sign in with their password only.', 'Turn off', 'danger');
    }

    public function turnOff(int $userId, ManageTwoStep $twoStep): void
    {
        $this->ensureConfirmed();
        $person = $this->person($userId);

        $twoStep->turnOff($person, $this->viewer());
        $this->toastWarning('Two-step is off for '.$person->name);
    }

    public function showQrFor(int $userId): void
    {
        $person = $this->person($userId);
        abort_unless($person->hasTwoFactorEnabled(), 409, 'Turn two-step on first.');

        $this->openQr($person);
    }

    public function closeQr(): void
    {
        $this->qrFor = null;
        $this->showQr = false;
    }

    public function render(): View
    {
        $unlocked = $this->unlocked();
        $like = '%'.$this->search.'%';

        $people = $this->manageable()
            ->when($this->tab === 'on', fn (Builder $query): Builder => $query->whereNotNull('two_factor_confirmed_at'))
            ->when($this->tab === 'off', fn (Builder $query): Builder => $query->whereNull('two_factor_confirmed_at'))
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->orderBy('name')
            ->paginate($this->perPage);

        $qrPerson = $unlocked && $this->showQr && $this->qrFor !== null ? $this->manageable()->find($this->qrFor) : null;

        return view('auth::livewire.admin.two-step-panel', [
            'unlocked' => $unlocked,
            'until' => $unlocked ? (int) session(self::UNLOCKED) : null,
            'people' => $people,
            'codes' => $unlocked ? $people->getCollection()->mapWithKeys(fn (User $person): array => [$person->getKey() => ManageTwoStep::currentCode($person)])->all() : [],
            'secondsLeft' => ManageTwoStep::secondsLeft(),
            'counts' => [
                'all' => $this->manageable()->count(),
                'on' => $this->manageable()->whereNotNull('two_factor_confirmed_at')->count(),
                'off' => $this->withoutTwoStep()->count(),
            ],
            'qrPerson' => $qrPerson,
            'qrSvg' => $qrPerson?->twoFactorQrCodeSvg(),
            'qrSecret' => $qrPerson !== null ? ManageTwoStep::secret($qrPerson) : null,
            'forIt' => $this->viewer()->isIt(),
        ]);
    }

    /**
     * The people this viewer looks after: the same rule as UserPolicy::manageTwoStep, as a query.
     *
     * @return Builder<User>
     */
    private function manageable(): Builder
    {
        $viewer = $this->viewer();

        return User::query()
            ->whereKeyNot($viewer->getKey())
            ->when(
                $viewer->isGlobalAdmin(),
                fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->whereNull('role')->orWhere('role', '!=', GlobalRole::SuperAdmin->value)),
                fn (Builder $query): Builder => $query->where(fn (Builder $q): Builder => $q->whereNull('role')->orWhere('role', GlobalRole::WebsiteLeads->value)),
            );
    }

    /** @return Builder<User> */
    private function withoutTwoStep(): Builder
    {
        return $this->manageable()->whereNull('two_factor_confirmed_at');
    }

    private function person(int $userId): User
    {
        $this->ensureUnlocked();

        $person = User::query()->findOrFail($userId);
        $this->authorize('manageTwoStep', $person);

        return $person;
    }

    private function openQr(User $person): void
    {
        $this->qrFor = (int) $person->getKey();
        $this->showQr = true;

        Audit::log('auth.two_step_qr_shown', $person, ['email' => $person->email], 'Two-step QR code of '.$person->name.' shown', logName: 'auth');
    }

    private function unlocked(): bool
    {
        $until = session(self::UNLOCKED);

        return is_int($until) && $until > now()->getTimestamp();
    }

    private function ensureUnlocked(): void
    {
        abort_unless($this->unlocked(), 403, 'Open the two-step codes with your password first.');
    }

    private function viewer(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
