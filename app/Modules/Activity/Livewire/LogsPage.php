<?php

declare(strict_types=1);

namespace App\Modules\Activity\Livewire;

use App\Modules\Activity\Jobs\BuildLogExport;
use App\Modules\Activity\Jobs\DeleteLogsBefore;
use App\Modules\Activity\Models\Activity;
use App\Modules\Activity\Models\LogArchive;
use App\Modules\Activity\Models\LogExport;
use App\Modules\Activity\Settings\LogSettings;
use App\Modules\Activity\Support\LogArchiver;
use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Logs: the audit trail with filters, downloads of the last 7, 15 or 30
 * days, the daily archives, retention settings and the storage card. Admins
 * see every brand; a Manager sees and downloads their own departments' logs.
 */
#[Layout('components.layouts.app')]
#[Title('Logs')]
final class LogsPage extends Component
{
    use WithConfirmation;
    use WithPagination;
    use WithToasts;

    /** Areas from the "What is logged" table: label => log names. */
    public const AREAS = [
        'auth' => 'Sign-in',
        'leads' => 'Leads',
        'sales' => 'Sales',
        'messages' => 'Messages',
        'members' => 'Members',
        'departments' => 'Departments',
        'brands' => 'Brands',
        'sites' => 'Websites',
        'integrations' => 'Integrations',
        'system' => 'System',
    ];

    public ?Brand $brand = null;

    #[Url(as: 'b', except: '')]
    public string $brandFilter = '';

    #[Url(except: '')]
    public string $person = '';

    #[Url(except: '')]
    public string $area = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $until = '';

    #[Url(except: '')]
    public string $search = '';

    public string $deleteBefore = '';

    public int $databaseDays = 30;

    public int $archiveDays = 180;

    public ?int $openEntry = null;

    public function mount(LogSettings $settings, ?Brand $brand = null): void
    {
        $this->brand = $brand !== null && $brand->exists ? $brand : null;

        $user = $this->user();
        abort_unless($user->isGlobalAdmin() || ($this->brand !== null && $user->isManagerIn($this->brand) && $user->can(Permission::LogsView->value)), 403);

        $this->databaseDays = $settings->database_days;
        $this->archiveDays = $settings->archive_days;
        $this->deleteBefore = now()->subDays(LogSettings::PROTECTED_DAYS)->toDateString();
    }

    public function paginationView(): string
    {
        return 'pagination.lq';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['brandFilter', 'person', 'area', 'from', 'until', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('brandFilter', 'person', 'area', 'from', 'until', 'search');
        $this->resetPage();
    }

    public function toggle(int $entryId): void
    {
        $this->openEntry = $this->openEntry === $entryId ? null : $entryId;
    }

    /** Download last 7 / 15 / 30 days: built in the queue, link in notifications. */
    public function export(int $days): void
    {
        $user = $this->user();
        abort_unless($user->isGlobalAdmin() || $user->can(Permission::LogsDownload->value), 403);
        abort_unless(in_array($days, LogExport::RANGES, true), 422);

        $export = LogExport::query()->create([
            'user_id' => $user->getKey(),
            'brand_id' => $this->scopeBrandId(),
            'department_ids' => $this->scopeDepartmentIds(),
            'range_days' => $days,
        ]);

        BuildLogExport::dispatch((int) $export->getKey());

        Audit::log('system.logs_export_requested', null, ['days' => $days], 'Download of the last '.$days.' days of logs requested', brand: $this->scopeBrandId(), logName: 'system');

        $this->toastSuccess('Preparing your download', 'A link appears in your notifications in a moment and works for 24 hours.');
    }

    public function saveRetention(LogSettings $settings): void
    {
        abort_unless($this->user()->isGlobalAdmin(), 403);

        $this->validate([
            'databaseDays' => ['required', Rule::in(LogSettings::DATABASE_OPTIONS)],
            'archiveDays' => ['required', Rule::in(LogSettings::ARCHIVE_OPTIONS)],
        ]);

        $before = ['database_days' => $settings->database_days, 'archive_days' => $settings->archive_days];
        $settings->database_days = $this->databaseDays;
        $settings->archive_days = $this->archiveDays;
        $settings->save();

        Audit::log('system.log_retention_changed', null, ['before' => $before, 'after' => ['database_days' => $this->databaseDays, 'archive_days' => $this->archiveDays]], 'Log retention changed', logName: 'system');

        $this->toastSuccess('Retention saved', 'It applies from the next nightly clean-up.');
    }

    public function confirmDelete(): void
    {
        abort_unless($this->user()->isGlobalAdmin(), 403);

        $this->validate([
            'deleteBefore' => ['required', 'date', 'before_or_equal:'.now()->subDays(LogSettings::PROTECTED_DAYS)->toDateString()],
        ], messages: ['deleteBefore.before_or_equal' => 'The last '.LogSettings::PROTECTED_DAYS.' days cannot be deleted.']);

        $this->askConfirmation('deleteLogs', [], 'Delete logs before '.$this->deleteBefore.'?', 'They are archived to the daily files first and then removed from this screen. Each lead keeps its own timeline. This cannot be undone here.', 'Delete logs', 'danger', password: true);
    }

    public function deleteLogs(): void
    {
        abort_unless($this->user()->isGlobalAdmin(), 403);

        // Checked again: the confirm dialog must not be a way around the 7-day rule.
        if ($this->deleteBefore === '' || $this->deleteBefore > now()->subDays(LogSettings::PROTECTED_DAYS)->toDateString()) {
            $this->toastDanger('The last '.LogSettings::PROTECTED_DAYS.' days cannot be deleted.');

            return;
        }

        DeleteLogsBefore::dispatch($this->deleteBefore, (int) $this->user()->getKey());

        $this->toastSuccess('Deleting in the background', 'You get a notification when it is done.');
    }

    public function render(): View
    {
        $user = $this->user();
        $admin = $user->isGlobalAdmin();

        $entries = $this->query()->with(['causer', 'brand', 'department'])->latest('id')->paginate(50);

        return view('activity::livewire.logs-page', [
            'entries' => $entries,
            'areas' => self::AREAS,
            'brands' => $this->brand === null ? Brand::query()->orderBy('name')->pluck('name', 'id') : collect(),
            'people' => $this->peopleOptions(),
            'isAdmin' => $admin,
            'isSuperAdmin' => $user->isGlobalAdmin(),
            'canDownload' => $admin || $user->can(Permission::LogsDownload->value),
            'ranges' => LogExport::RANGES,
            'exports' => LogExport::query()->where('user_id', $user->getKey())->latest('id')->limit(5)->get(),
            'archives' => $admin && $this->brand === null
                ? LogArchive::query()
                    ->when($this->from !== '', fn (Builder $query): Builder => $query->whereDate('date', '>=', $this->from))
                    ->when($this->until !== '', fn (Builder $query): Builder => $query->whereDate('date', '<=', $this->until))
                    ->latest('date')->limit(31)->get()
                : collect(),
            'storage' => $admin && $this->brand === null ? app(LogArchiver::class)->storage() : null,
            'databaseOptions' => LogSettings::DATABASE_OPTIONS,
            'archiveOptions' => LogSettings::ARCHIVE_OPTIONS,
            'protectedDays' => LogSettings::PROTECTED_DAYS,
            'filtered' => $this->brandFilter.$this->person.$this->area.$this->from.$this->until.$this->search !== '',
        ]);
    }

    // -------------------------------------------------------------- internals

    /** @return Builder<Activity> */
    private function query(): Builder
    {
        $like = '%'.$this->search.'%';

        return Activity::query()
            ->when($this->scopeBrandId() !== null, fn (Builder $query): Builder => $query->where('brand_id', $this->scopeBrandId()))
            ->when($this->scopeDepartmentIds() !== null, fn (Builder $query): Builder => $query->whereIn('department_id', $this->scopeDepartmentIds() ?? []))
            ->when(ctype_digit($this->person), fn (Builder $query): Builder => $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', (int) $this->person))
            ->when(array_key_exists($this->area, self::AREAS), fn (Builder $query): Builder => $query->where('log_name', $this->area))
            ->when($this->from !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->until !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $this->until))
            // Search by lead name or email.
            ->when($this->search !== '', fn (Builder $query): Builder => $query
                ->where('subject_type', (new Lead)->getMorphClass())
                ->whereIn('subject_id', Lead::query()->acrossBrands()->withTrashed()
                    ->where(fn (Builder $lead): Builder => $lead->where('name', 'like', $like)->orWhere('email', 'like', $like))
                    ->select('id')));
    }

    /** Admin area: the chosen brand or all. Brand area: always that brand. */
    private function scopeBrandId(): ?int
    {
        if ($this->brand !== null) {
            return (int) $this->brand->getKey();
        }

        return ctype_digit($this->brandFilter) ? (int) $this->brandFilter : null;
    }

    /** @return list<int>|null a Manager is held to the departments they lead */
    private function scopeDepartmentIds(): ?array
    {
        $user = $this->user();

        if ($user->isGlobalAdmin() || $this->brand === null) {
            return null;
        }

        $ids = array_map('intval', $user->managedDepartmentIds($this->brand));

        return $ids === [] ? [0] : $ids;
    }

    /** @return array<int, string> */
    private function peopleOptions(): array
    {
        $ids = $this->query()->reorder()->whereNotNull('causer_id')->where('created_at', '>=', now()->subDays(90))->distinct()->limit(300)->pluck('causer_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
