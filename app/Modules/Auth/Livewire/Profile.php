<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Sales\Support\Progress;
use App\Modules\Sales\Support\SalesReport;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** Name, email, phone, timezone and photo. */
#[Layout('components.layouts.app')]
#[Title('Profile')]
final class Profile extends Component
{
    use WithFileUploads;
    use WithToasts;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $timezone = '';

    public ?TemporaryUploadedFile $avatar = null;

    public function mount(): void
    {
        $user = $this->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        $this->timezone = (string) ($user->timezone ?? config('liquify.default_timezone'));
    }

    public function save(): void
    {
        $user = $this->user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($this->avatar !== null) {
            $user->avatar_path = $this->avatar->store('avatars', 'public');
            $this->avatar = null;
        }

        $user->forceFill([
            'name' => $data['name'],
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?: null,
            'timezone' => $data['timezone'],
        ])->save();

        Audit::log('members.profile_updated', $user, [], 'Profile updated', causer: $user, logName: 'members');

        $this->toastSuccess('Profile saved');
    }

    public function removeAvatar(): void
    {
        $this->user()->forceFill(['avatar_path' => null])->save();
        $this->toastSuccess('Photo removed');
    }

    public function render(): View
    {
        return view('auth::livewire.profile', [
            'user' => $this->user(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'targets' => $this->targets(),
        ]);
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
