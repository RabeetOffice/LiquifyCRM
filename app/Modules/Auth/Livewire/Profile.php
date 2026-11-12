<?php

declare(strict_types=1);

namespace App\Modules\Auth\Livewire;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Chat\Support\AgentCard;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\SmallPhoto;
use App\Modules\Core\Support\ViewerTime;
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

    /** How visitors of the website chat see this person (empty: first name, designation, profile photo). */
    public string $chatName = '';

    public string $chatTitle = '';

    public ?TemporaryUploadedFile $chatPhoto = null;

    public function mount(): void
    {
        $user = $this->user();

        $this->name = $user->name;
        $this->designation = (string) $user->designation;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        // Empty is "Automatic": this computer's time zone (ViewerTime).
        $this->timezone = (string) $user->timezone;

        if (AgentCard::ready()) {
            $this->chatName = (string) $user->chat_name;
            $this->chatTitle = (string) $user->chat_title;
        }
    }

    public function save(): void
    {
        $user = $this->user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ]);

        $user->forceFill([
            'name' => $data['name'],
            'designation' => $data['designation'] ?: null,
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?: null,
            'timezone' => $data['timezone'] ?: null,
        ])->save();

        Audit::log('members.profile_updated', $user, [], 'Profile updated', causer: $user, logName: 'members');

        $this->toastSuccess('Profile saved');
    }

    /** The photo saves itself as soon as it is chosen: no second Save button. */
    public function updatedAvatar(): void
    {
        $this->validate(['avatar' => ['required', 'image', 'max:2048']], attributes: ['avatar' => 'photo']);

        $user = $this->user();
        // Kept small: the photo is shown at a few dozen pixels all over the app.
        $user->forceFill(['avatar_path' => $this->avatar !== null ? SmallPhoto::store((string) $this->avatar->get(), $this->avatar->extension()) : null])->save();
        $this->avatar = null;

        $this->toastSuccess('Photo updated');
    }

    /** The name and title the website chat shows for this person. */
    public function saveChatProfile(): void
    {
        abort_unless(AgentCard::ready(), 404);

        $data = $this->validate([
            'chatName' => ['nullable', 'string', 'max:60'],
            'chatTitle' => ['nullable', 'string', 'max:80'],
        ], attributes: ['chatName' => 'name in the chat', 'chatTitle' => 'title in the chat']);

        $user = $this->user();
        $user->forceFill(['chat_name' => trim((string) $data['chatName']) ?: null, 'chat_title' => trim((string) $data['chatTitle']) ?: null])->save();

        $this->toastSuccess('Saved', 'Visitors see it in the chat box the next time it asks.');
    }

    /** The chat photo saves itself as soon as it is chosen, made small and square. */
    public function updatedChatPhoto(): void
    {
        abort_unless(AgentCard::ready(), 404);
        $this->validate(['chatPhoto' => ['required', 'image', 'max:4096']], attributes: ['chatPhoto' => 'photo']);

        $small = AgentCard::small((string) file_get_contents((string) $this->chatPhoto?->getRealPath()));
        $this->chatPhoto = null;

        if ($small === null) {
            $this->addError('chatPhoto', 'That picture could not be read. Try a JPG or PNG.');

            return;
        }

        $this->user()->forceFill(['chat_photo' => $small])->save();
        $this->toastSuccess('Chat photo updated');
    }

    public function removeChatPhoto(): void
    {
        abort_unless(AgentCard::ready(), 404);
        $this->user()->forceFill(['chat_photo' => null])->save();
        $this->toastSuccess('Chat photo removed', 'The chat shows your profile photo, if you have one.');
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
            // How this person shows in the website chat, for those who answer it (Admins, Managers, Agents).
            'chatCard' => AgentCard::ready() && ($user->isGlobalAdmin() || $memberships->isNotEmpty()) ? AgentCard::of($user) : null,
            'memberships' => $memberships,
            'departments' => $user->isGlobalAdmin() ? collect() : $user->departments()->acrossBrands()->orderBy('name')->get(),
            'facts' => $this->facts($user),
            'timezones' => DateTimeZone::listIdentifiers(),
            'computerZone' => ViewerTime::computerZone(),
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
