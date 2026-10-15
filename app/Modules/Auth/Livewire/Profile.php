<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\Progress;
use App\Modules\Sales\Support\SalesReport;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A person's own profile: who they are (photo, name, designation, role,
 * brands and departments), how their month is going, and their details.
 */
#[Layout('components.layouts.app')]
#[Title('Profile')]
final class Profile extends Component
{
    use WithFileUploads;
    use WithToasts;

    public string $name = '';

    public string $designation = '';

    public string $email = '';

    public string $phone = '';

    public string $timezone = '';

    public ?TemporaryUploadedFile $avatar = null;

    public function mount(): void
    {
        $user = $this->user();

        $this->name = $user->name;
        $this->designation = (string) $user->designation;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        $this->timezone = (string) ($user->timezone ?? config('liquify.default_timezone'));
    }

    public function save(): void
    {
        $user = $this->user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ]);

        $user->forceFill([
            'name' => $data['name'],
            'designation' => $data['designation'] ?: null,
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?: null,
            'timezone' => $data['timezone'],
        ])->save();

        Audit::log('members.profile_updated', $user, [], 'Profile updated', causer: $user, logName: 'members');

        $this->toastSuccess('Profile saved');
    }

    /** The photo saves itself as soon as it is chosen: no second Save button. */
    public function updatedAvatar(): void
    {
        $this->validate(['avatar' => ['required', 'image', 'max:2048']], attributes: ['avatar' => 'photo']);

        $user = $this->user();
        $user->forceFill(['avatar_path' => $this->avatar?->store('avatars', 'public')])->save();
        $this->avatar = null;

        $this->toastSuccess('Photo updated');
    }

    public function removeAvatar(): void
    {
        $this->user()->forceFill(['avatar_path' => null])->save();
        $this->toastSuccess('Photo removed');
    }

    public function render(): View
    {
        $user = $this->user();
        $memberships = $user->isGlobalAdmin()
            ? collect()
            : Membership::query()->acrossBrands()->where('user_id', $user->getKey())->with('brand')->get()->filter(fn (Membership $membership): bool => $membership->brand !== null)->values();

        return view('auth::livewire.profile', [
            'user' => $user,
            'memberships' => $memberships,
            'departments' => $user->isGlobalAdmin() ? collect() : $user->departments()->acrossBrands()->orderBy('name')->get(),
            'facts' => $this->facts($user),
            'timezones' => DateTimeZone::listIdentifiers(),
            'targets' => $this->targets(),
        ]);
    }

    /**
     * Four numbers under the name: what this person has on their plate.
     *
     * @return list<array{label: string, value: string}>
     */
    private function facts(User $user): array
    {
        $since = ['label' => 'With LiquifyCRM since', 'value' => $user->created_at?->format('M Y') ?? '—'];

        if ($user->isGlobalAdmin()) {
            return [
                ['label' => 'Brands', 'value' => (string) Brand::query()->where('is_active', true)->count()],
                ['label' => 'People', 'value' => (string) User::query()->count()],
                ['label' => 'Leads this month', 'value' => number_format(Lead::query()->acrossBrands()->whereIn('brand_id', Brand::query()->select('id'))->where('created_at', '>=', now()->startOfMonth())->count())],
                $since,
            ];
        }

        $mine = fn () => Lead::query()->acrossBrands()->where('assigned_to', $user->getKey());

        return [
            ['label' => 'Open leads', 'value' => number_format($mine()->whereHas('status', fn (Builder $status): Builder => $status->where('is_won', false)->where('is_lost', false))->count())],
            ['label' => 'Leads this month', 'value' => number_format($mine()->where('assigned_at', '>=', now()->startOfMonth())->count())],
            ['label' => 'Sales this month', 'value' => number_format(Sale::query()->acrossBrands()->where('user_id', $user->getKey())->inPeriod(SalesReport::currentPeriod())->count())],
            $since,
        ];
    }

    /**
     * This month against the person's own target, one row per brand they work
     * in. Global admins carry no personal target.
     *
     * @return list<array{brand: Brand, progress: Progress}>
     */
    private function targets(): array
    {
        $user = $this->user();

        if ($user->isGlobalAdmin()) {
            return [];
        }

        $period = SalesReport::currentPeriod();

        return $user->accessibleBrands()
            ->map(fn (Brand $brand): array => ['brand' => $brand, 'progress' => SalesReport::forPerson($brand, $user, $period)->progress()])
            ->values()
            ->all();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
