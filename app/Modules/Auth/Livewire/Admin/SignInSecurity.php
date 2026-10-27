<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire\Admin;

use App\Modules\Activity\Models\Activity;
use App\Modules\Auth\Actions\ChangeSignInRules;
use App\Modules\Auth\Actions\DecideDevice;
use App\Modules\Auth\Enums\DeviceStatus;
use App\Modules\Auth\Enums\NetworkRule;
use App\Modules\Auth\Enums\PhoneRule;
use App\Modules\Auth\Enums\SignInRefusal;
use App\Modules\Auth\Models\SignInAddress;
use App\Modules\Auth\Models\SignInDevice;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\SignInGate;
use App\Modules\Auth\Support\SignInRules;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Sign-in security (26 Oct 2026, the user's request): where people may sign
 * in from. The rule for everybody (anywhere or the office network only; any
 * phone, only approved phones or none), the office and blocked addresses,
 * the phones people used with Approve and Block, and the latest refusals and
 * sign-ins on phones. A person's own rule is set on People › Edit. Nothing
 * may be saved that would sign out the Admin who is saving it.
 */
#[Layout('components.layouts.app')]
final class SignInSecurity extends Component
{
    use WithToasts;

    public string $network = 'anywhere';

    public string $phones = 'any';

    public string $officeAddress = '';

    public string $officeLabel = '';

    public string $blockedAddress = '';

    public string $blockedLabel = '';

    /** waiting | approved | blocked | all */
    public string $devices = 'all';

    /** refused | phones | all */
    public string $feed = 'all';

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->canManageSystem(), 403);

        $rules = SignInRules::current();
        $this->network = $rules->network->value;
        $this->phones = $rules->phones->value;
        $this->devices = SignInRules::ready() && SignInDevice::query()->withStatus(DeviceStatus::Waiting)->exists() ? 'waiting' : 'all';
    }

    public function saveRules(ChangeSignInRules $change): void
    {
        $this->ready();

        $this->validate([
            'network' => ['required', Rule::enum(NetworkRule::class)],
            'phones' => ['required', Rule::enum(PhoneRule::class)],
        ]);

        $network = NetworkRule::from($this->network);
        $phones = PhoneRule::from($this->phones);
        $rules = SignInRules::current();

        if ($network === NetworkRule::Office && $rules->office === []) {
            throw ValidationException::withMessages(['network' => 'Add the office\'s internet address below first: with none, nobody could sign in.']);
        }

        $this->refuseIfLockedOut($rules->with(network: $network, phones: $phones), 'network');

        $change->forEverybody($network, $phones);
        $this->resetErrorBag();
        $this->toastSuccess('Sign-in rules saved', 'They apply from the next click of everybody signed in. People with their own rule keep it.');
    }

    public function addOffice(ChangeSignInRules $change): void
    {
        $address = $this->typedAddress('officeAddress');

        $change->addAddress(SignInAddress::OFFICE, $address, $this->label($this->officeLabel), $this->admin());
        $this->reset('officeAddress', 'officeLabel');
        $this->resetErrorBag();
        $this->toastSuccess('Office address added', $address);
    }

    /** The address this Admin is on now, as an office address. */
    public function addMyAddress(ChangeSignInRules $change): void
    {
        $this->ready();
        $address = (string) request()->ip();

        $change->addAddress(SignInAddress::OFFICE, $address, $this->label($this->officeLabel) ?? 'Added from '.SignInGate::deviceOf(request())->name, $this->admin());
        $this->reset('officeAddress', 'officeLabel');
        $this->resetErrorBag();
        $this->toastSuccess('Your address is now an office address', $address);
    }

    public function removeOffice(int $id, ChangeSignInRules $change): void
    {
        $this->ready();
        $row = SignInAddress::query()->where('kind', SignInAddress::OFFICE)->findOrFail($id);
        $rules = SignInRules::current();
        $left = array_values(array_diff($rules->office, [$row->address]));

        if ($left === [] && $this->anybodyOfficeOnly($rules)) {
            $this->toastDanger('This is the last office address', 'People with the office rule could not sign in anywhere. Add the new address first, or change their rule.');

            return;
        }

        if ($this->wouldLockOut($rules->with(office: $left))) {
            $this->toastDanger('That would sign you out', 'You are signed in from this address and your rule is the office network only.');

            return;
        }

        $change->removeAddress($row);
        $this->toastSuccess('Office address removed', $row->address);
    }

    public function addBlocked(ChangeSignInRules $change): void
    {
        $address = $this->typedAddress('blockedAddress');

        if (SignInRules::matches(request()->ip(), [$address])) {
            throw ValidationException::withMessages(['blockedAddress' => 'That is the address you are using now: blocking it would sign you out.']);
        }

        $change->addAddress(SignInAddress::BLOCKED, $address, $this->label($this->blockedLabel), $this->admin());
        $this->reset('blockedAddress', 'blockedLabel');
        $this->resetErrorBag();
        $this->toastSuccess('Address blocked', $address.': nobody can open LiquifyCRM from it, and whoever is signed in there is signed out.');
    }

    /** Block an address straight from the list of sign-ins. */
    public function blockAddress(string $ip, ChangeSignInRules $change): void
    {
        $this->ready();
        $address = SignInRules::normalise($ip) ?? abort(422);

        if (SignInRules::matches(request()->ip(), [$address])) {
            $this->toastDanger('That is your own address', 'Blocking it would sign you out.');

            return;
        }

        $change->addAddress(SignInAddress::BLOCKED, $address, 'Blocked from the sign-in list', $this->admin());
        $this->toastSuccess('Address blocked', $address);
    }

    public function removeBlocked(int $id, ChangeSignInRules $change): void
    {
        $this->ready();
        $row = SignInAddress::query()->where('kind', SignInAddress::BLOCKED)->findOrFail($id);

        $change->removeAddress($row);
        $this->toastSuccess('Address unblocked', $row->address);
    }

    public function approveDevice(int $id, DecideDevice $decide): void
    {
        $device = $this->device($id);
        $decide->approve($device, $this->admin());
        $this->leaveEmptyWaiting();

        $this->toastSuccess('Phone approved', ($device->user->name ?? 'They').' can sign in on it now.');
    }

    public function blockDevice(int $id, DecideDevice $decide): void
    {
        $device = $this->device($id);
        $token = SignInGate::tokenOf(request());

        if ($token !== null && hash_equals($device->token_hash, SignInDevice::hashOf($token))) {
            $this->toastDanger('This is the phone you are using now', 'Blocking it would sign you out.');

            return;
        }

        $decide->block($device, $this->admin());
        $this->leaveEmptyWaiting();
        $this->toastSuccess('Phone blocked', 'Nobody can use it any more; whoever is signed in on it is signed out at their next click.');
    }

    public function forgetDevice(int $id, DecideDevice $decide): void
    {
        $device = $this->device($id);
        $decide->forget($device);
        $this->leaveEmptyWaiting();

        $this->toastSuccess('Removed from the list', 'If it is used again it shows here again.');
    }

    public function render(): View
    {
        $ready = SignInRules::ready();
        $rules = SignInRules::current();
        $me = $this->admin();
        $here = SignInGate::deviceOf(request());
        $addresses = $ready ? SignInAddress::query()->with('creator')->orderBy('created_at')->get() : new Collection;

        return view('auth::livewire.admin.sign-in-security', [
            'ready' => $ready,
            'enforced' => SignInRules::enforced(),
            'rules' => $rules,
            'ip' => (string) request()->ip(),
            'here' => $here,
            'atOffice' => $rules->isOffice(request()->ip()),
            'myNetwork' => $rules->ownNetwork($me),
            'myPhones' => $rules->ownPhones($me),
            'networkRules' => NetworkRule::cases(),
            'phoneRules' => PhoneRule::cases(),
            'office' => $addresses->where('kind', SignInAddress::OFFICE)->values(),
            'blocked' => $addresses->where('kind', SignInAddress::BLOCKED)->values(),
            'counts' => $ready ? $this->deviceCounts() : ['waiting' => 0, 'approved' => 0, 'blocked' => 0, 'all' => 0],
            'deviceList' => $ready ? $this->deviceList() : new Collection,
            'ownRules' => $ready ? $this->peopleWithOwnRules() : new Collection,
            'entries' => $ready ? $this->entries() : new Collection,
            'refusals' => collect(SignInRefusal::cases())->mapWithKeys(fn (SignInRefusal $refusal): array => [$refusal->value => $refusal->getLabel()])->all(),
        ])->title('Sign-in security');
    }

    /** @return array{waiting: int, approved: int, blocked: int, all: int} */
    private function deviceCounts(): array
    {
        $counts = SignInDevice::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total): int => (int) $total);

        return [
            'waiting' => $counts[DeviceStatus::Waiting->value] ?? 0,
            'approved' => $counts[DeviceStatus::Approved->value] ?? 0,
            'blocked' => $counts[DeviceStatus::Blocked->value] ?? 0,
            'all' => (int) $counts->sum(),
        ];
    }

    /** @return Collection<int, SignInDevice> */
    private function deviceList(): Collection
    {
        $status = DeviceStatus::tryFrom($this->devices);

        return SignInDevice::query()
            ->with(['user', 'decider'])
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status?->value))
            // Waiting first, then the most recently used.
            ->orderByRaw('case when status = ? then 0 else 1 end', [DeviceStatus::Waiting->value])
            ->orderByDesc('last_seen_at')
            ->limit(200)
            ->get();
    }

    /** @return Collection<int, User> */
    private function peopleWithOwnRules(): Collection
    {
        return User::query()
            ->where(fn (Builder $query) => $query->whereNotNull('sign_in_network')->orWhereNotNull('sign_in_phones'))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'avatar_path', 'role', 'sign_in_network', 'sign_in_phones']);
    }

    /**
     * Refusals and sign-ins on phones, newest first, from the log (kept as
     * long as the log retention on the Logs screen says).
     *
     * @return Collection<int, Activity>
     */
    private function entries(): Collection
    {
        $phone = fn (Builder $query) => $query->where('event', 'auth.login')->whereIn('properties->device', ['phone', 'tablet']);
        $refused = fn (Builder $query) => $query->whereIn('event', ['auth.refused', 'auth.address_blocked']);

        /** @var Collection<int, Activity> $entries */
        $entries = Activity::query()
            ->with('causer')
            ->where('log_name', 'auth')
            ->where(fn (Builder $query) => match ($this->feed) {
                'refused' => $refused($query),
                'phones' => $phone($query)->orWhere(fn (Builder $tried) => $tried->where('event', 'auth.refused')->whereIn('properties->device', ['phone', 'tablet'])),
                default => $refused($query)->orWhere($phone),
            })
            ->latest('id')
            ->limit(40)
            ->get();

        return $entries;
    }

    private function device(int $id): SignInDevice
    {
        $this->ready();

        return SignInDevice::query()->with('user')->findOrFail($id);
    }

    /** Once the last waiting phone is dealt with, the list shows them all instead of an empty view. */
    private function leaveEmptyWaiting(): void
    {
        if ($this->devices === 'waiting' && ! SignInDevice::query()->withStatus(DeviceStatus::Waiting)->exists()) {
            $this->devices = 'all';
        }
    }

    /** A typed office or blocked address in its stored form, or a message under the field. */
    private function typedAddress(string $field): string
    {
        $this->ready();
        $address = SignInRules::normalise((string) $this->{$field});

        if ($address === null) {
            throw ValidationException::withMessages([$field => 'Type an internet address such as 203.0.113.7, or a range such as 203.0.113.0/24.']);
        }

        return $address;
    }

    private function label(string $typed): ?string
    {
        $typed = trim($typed);

        return $typed === '' ? null : mb_substr($typed, 0, 120);
    }

    /** Whether anybody would need the office network under these rules. */
    private function anybodyOfficeOnly(SignInRules $rules): bool
    {
        return $rules->network === NetworkRule::Office || User::query()->where('sign_in_network', NetworkRule::Office->value)->exists();
    }

    private function wouldLockOut(SignInRules $rules): bool
    {
        return app(SignInGate::class)->refusal($this->admin(), request()->ip(), SignInGate::deviceOf(request()), SignInGate::tokenOf(request()), $rules) !== null;
    }

    private function refuseIfLockedOut(SignInRules $rules, string $field): void
    {
        $refusal = app(SignInGate::class)->refusal($this->admin(), request()->ip(), SignInGate::deviceOf(request()), SignInGate::tokenOf(request()), $rules);

        if ($refusal === null) {
            return;
        }

        throw ValidationException::withMessages([$field => match ($refusal) {
            SignInRefusal::OutsideOffice => 'That would sign you out: you are not on an office address now ('.request()->ip().'). Click "Add my address" below if you are in the office, or give yourself "Anywhere" on People › Edit.',
            SignInRefusal::NoPhones, SignInRefusal::PhoneWaiting, SignInRefusal::PhoneUnknown => 'That would sign you out: you are on a '.mb_strtolower(SignInGate::deviceOf(request())->kind->getLabel()).' that is not approved. Approve it below first, or save this from a computer.',
            default => 'That would sign you out: '.mb_strtolower($refusal->getLabel()).'.',
        }]);
    }

    private function ready(): void
    {
        abort_unless(SignInRules::ready(), 409, 'Click "Update system" first.');
    }

    private function admin(): User
    {
        /** @var User $admin */
        $admin = auth()->user();

        return $admin;
    }
}
