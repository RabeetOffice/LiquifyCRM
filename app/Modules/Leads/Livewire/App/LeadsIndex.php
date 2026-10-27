<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\ViewerTime;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\ArchiveLead;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Actions\SendLeadToDepartment;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Exceptions\LeadIsWon;
use App\Modules\Leads\Exports\LeadsExport;
use App\Modules\Leads\Livewire\Concerns\PreviewsLead;
use App\Modules\Leads\Livewire\Concerns\TakesLeads;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\AssignableAgents;
use App\Modules\Leads\Support\ContactVisibility;
use App\Modules\Leads\Support\ProfileNames;
use App\Modules\Sales\Models\LeadDistribution;
use App\Modules\Sites\Support\LeadSites;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Leads list: saved views (All, New, Unassigned, Mine, Follow-ups today,
 * Won), filters shown as chips, search, bulk assign / send / status /
 * delete, CSV import and export, and the lead slide-over.
 */
#[Layout('components.layouts.app')]
#[Title('Leads')]
final class LeadsIndex extends Component
{
    use AuthorizesRequests;
    use PreviewsLead;
    use TakesLeads;
    use WithConfirmation;
    use WithTableState {
        sortBy as private sortByColumn;
    }

    private const FILTERS = ['status', 'source', 'department', 'owner', 'assigned', 'from', 'until'];

    /** The Sort choice above the list: column and direction for each. */
    public const ORDERS = [
        'newest' => ['Newest first', 'created_at', 'desc'],
        'oldest' => ['Oldest first', 'created_at', 'asc'],
        'added' => ['Recently added', 'id', 'desc'],
        'activity' => ['Recent activity', 'last_activity_at', 'desc'],
        'follow-up' => ['Next follow-up', 'next_follow_up_at', 'asc'],
    ];

    /** The Sort choice; empty is the view's own (newest first, or the next follow-up on Follow-ups). */
    #[Url(except: '')]
    public string $order = '';

    public Brand $brand;

    #[Url(except: 'all')]
    public string $tab = 'all';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $department = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $assigned = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $until = '';

    public bool $showFilters = false;

    /** @var list<int> */
    public array $selected = [];

    public bool $showBulkAssign = false;

    public string $bulkAssignTo = '';

    public bool $showBulkSend = false;

    public string $bulkSendTo = '';

    public bool $showBulkStatus = false;

    public string $bulkStatusTo = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Lead::class);

        if (is_numeric(request()->query('open'))) {
            $this->open = (int) request()->query('open');
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'tab' || in_array($property, self::FILTERS, true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    /** A column heading takes over from the Sort choice; dates start with the newest. */
    public function sortBy(string $field): void
    {
        $fresh = $this->sort !== $field;
        $this->sortByColumn($field);
        $this->order = '';

        if ($fresh && in_array($field, ['created_at', 'next_follow_up_at', 'last_activity_at'], true)) {
            $this->direction = $field === 'next_follow_up_at' ? 'asc' : 'desc';
        }
    }

    public function updatedOrder(): void
    {
        $this->order = array_key_exists($this->order, self::ORDERS) ? $this->order : '';
        $this->reset('sort', 'direction');
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'source', 'department', 'owner', 'assigned', 'from', 'until', 'search');
        $this->resetPage();
    }

    /** The cross on one filter chip. */
    public function removeFilter(string $filter): void
    {
        if (! in_array($filter, self::FILTERS, true)) {
            return;
        }

        $this->reset($filter);
        $this->resetPage();
        $this->selected = [];
    }

    #[On('lead-updated')]
    public function refreshList(): void
    {
        // The list re-renders with the latest data.
    }

    #[On('lead-deleted')]
    public function leadDeleted(): void
    {
        $this->open = null;
        $this->selected = [];
    }

    /** The list behind redraws with the new leads; the import stays open for the workbook's next tab. */
    #[On('leads-imported')]
    public function leadsImported(): void
    {
        $this->resetPage();
    }

    // ---------------------------------------------------- assign from the list

    /** A person chosen in the menu that opens on a lead's Owner cell. */
    public function quickAssign(int $leadId, int $userId): void
    {
        $lead = Lead::query()->visibleTo($this->user())->findOrFail($leadId);
        $this->authorize('assign', $lead);

        $agent = app(AssignableAgents::class)->find($this->user(), $this->brand, $userId, $lead->department_id);

        if ($agent === null) {
            $this->toastWarning('Not assigned', 'Choose a person from the list.');

            return;
        }

        $previous = $lead->assigned_to;

        app(AssignLead::class)->handle($lead, $agent, $this->user());

        $this->toast($lead->name.' assigned to '.$agent->name, null, 'success', $this->undoPayload($lead, 'assign', $previous));
    }

    /** "Unassign" in the same menu (user request, 26 Oct 2026): back to its team's queue, for Admins and its Manager. */
    public function quickUnassign(int $leadId): void
    {
        $lead = Lead::query()->visibleTo($this->user())->findOrFail($leadId);
        $this->authorize('assign', $lead);

        $previous = $lead->assigned_to;

        if ($previous === null) {
            return;
        }

        app(AssignLead::class)->handle($lead, null, $this->user());

        $this->toast($lead->name.' unassigned', 'Back in the queue: nobody owns it now.', 'success', $this->undoPayload($lead, 'assign', $previous));
    }

    /** A team chosen in the same menu, by whoever may send leads to departments. */
    public function quickSend(int $leadId, int $departmentId): void
    {
        $lead = Lead::query()->visibleTo($this->user())->findOrFail($leadId);
        $this->authorize('distribute', $lead);

        $department = Department::query()->findOrFail($departmentId);

        app(SendLeadToDepartment::class)->handle($lead, $department, $this->user());

        $this->toastSuccess($lead->name.' sent to '.$department->name, 'Its Managers have been told.');
    }

    // ------------------------------------------------------------------ bulk

    public function bulkAssign(): void
    {
        $this->validate(['bulkAssignTo' => ['required', 'integer']]);

        $agentId = (int) $this->bulkAssignTo;

        // "Nobody": the chosen leads go back to the queue (26 Oct 2026).
        if ($agentId === 0) {
            $this->bulkUnassign();

            return;
        }

        abort_unless(array_key_exists($agentId, app(AssignableAgents::class)->for($this->user(), $this->brand)), 403);
        $agent = User::query()->findOrFail($agentId);
        $done = 0;
        $skipped = 0;

        foreach ($this->selectedLeads() as $lead) {
            // The same rule as one lead's assign menu: a lead in a department goes to somebody of that department.
            if (! $this->user()->can('assign', $lead) || app(AssignableAgents::class)->find($this->user(), $this->brand, $agentId, $lead->department_id) === null) {
                $skipped++;

                continue;
            }

            app(AssignLead::class)->handle($lead, $agent, $this->user());
            $done++;
        }

        if ($done > 1) {
            LeadDistribution::query()->create(['brand_id' => $this->brand->getKey(), 'from_user_id' => $this->user()->getKey(), 'to_type' => 'user', 'to_id' => $agent->getKey(), 'lead_count' => $done, 'method' => 'manual']);
        }

        $this->showBulkAssign = false;
        $this->selected = [];
        $this->toastSuccess("{$done} ".str('lead')->plural($done).' assigned to '.$agent->name, $skipped > 0 ? $skipped.' '.str('lead')->plural($skipped).' left as they were: '.($skipped === 1 ? 'it is' : 'they are').' in a department '.$agent->name.' does not work in.' : null);
    }

    private function bulkUnassign(): void
    {
        $done = 0;

        foreach ($this->selectedLeads() as $lead) {
            if ($lead->assigned_to === null || ! $this->user()->can('assign', $lead)) {
                continue;
            }

            app(AssignLead::class)->handle($lead, null, $this->user());
            $done++;
        }

        $this->showBulkAssign = false;
        $this->selected = [];
        $this->toastSuccess("{$done} ".str('lead')->plural($done).' unassigned', 'Back in the queue: nobody owns '.($done === 1 ? 'it' : 'them').' now.');
    }

    public function bulkSend(): void
    {
        $this->authorize('distribute', Lead::class);
        $this->validate(['bulkSendTo' => ['required', 'integer']]);
        abort_unless(array_key_exists((int) $this->bulkSendTo, $this->sendOptions()), 403);

        $department = Department::query()->findOrFail((int) $this->bulkSendTo);
        $leads = $this->selectedLeads();

        foreach ($leads as $lead) {
            app(SendLeadToDepartment::class)->handle($lead, $department, $this->user());
        }

        if ($leads->count() > 1) {
            LeadDistribution::query()->create(['brand_id' => $this->brand->getKey(), 'from_user_id' => $this->user()->getKey(), 'to_type' => 'department', 'to_id' => $department->getKey(), 'lead_count' => $leads->count(), 'method' => 'manual']);
        }

        $this->showBulkSend = false;
        $this->selected = [];
        $this->toastSuccess($leads->count().' '.str('lead')->plural($leads->count()).' sent to '.$department->name);
    }

    public function bulkStatus(): void
    {
        $this->validate(['bulkStatusTo' => ['required', 'integer']]);

        $status = LeadStatus::query()->findOrFail((int) $this->bulkStatusTo);
        $done = 0;

        if ($status->is_won) {
            $this->showBulkStatus = false;
            $this->toastWarning('Won needs an amount', 'Open each lead and click Mark as Won to record its sale.');

            return;
        }

        $won = 0;

        foreach ($this->selectedLeads() as $lead) {
            if ($this->user()->can('update', $lead)) {
                try {
                    app(ChangeLeadStatus::class)->handle($lead, $status, $this->user());
                    $done++;
                } catch (LeadIsWon) {
                    // Won with a sale: it stays in Won.
                    $won++;
                }
            }
        }

        $this->showBulkStatus = false;
        $this->selected = [];
        $this->toastSuccess("{$done} ".str('lead')->plural($done).' moved to '.$status->name, $won > 0 ? $won.' won '.str('lead')->plural($won).' with a sale stayed in Won.' : null);
    }

    public function confirmBulkDelete(): void
    {
        $this->authorize('deleteAny', Lead::class);

        $count = count($this->selected);
        $this->askConfirmation('bulkDelete', [], "Delete {$count} ".str('lead')->plural($count).'?', 'They move to the bin and can be restored by an Admin.', 'Delete', 'danger');
    }

    public function bulkDelete(): void
    {
        $this->authorize('deleteAny', Lead::class);

        $leads = $this->selectedLeads();

        foreach ($leads as $lead) {
            $lead->delete();
            Audit::log('leads.deleted', $lead, [], 'Lead deleted', logName: 'leads');
        }

        $this->selected = [];
        $this->toastSuccess($leads->count().' '.str('lead')->plural($leads->count()).' moved to the bin', 'Restore them from Bin, at the top of Leads.');
    }

    /** The ticked leads out of the lists (only those this person may archive: their own departments). */
    public function bulkArchive(): void
    {
        $this->authorize('archiveAny', Lead::class);

        [$done, $skipped] = $this->eachArchivable(fn (Lead $lead) => app(ArchiveLead::class)->handle($lead, $this->user()));

        $this->toastSuccess("{$done} ".str('lead')->plural($done).' archived', $skipped > 0 ? "{$skipped} left as they were: they are in a department you do not lead." : 'Find them under Archived. One comes back by itself if the person gets in touch again.');
    }

    public function bulkBringBack(): void
    {
        $this->authorize('archiveAny', Lead::class);

        [$done] = $this->eachArchivable(fn (Lead $lead) => app(ArchiveLead::class)->bringBack($lead, $this->user()));

        $this->toastSuccess("{$done} ".str('lead')->plural($done).' back in the lists');
    }

    /**
     * @param  callable(Lead): mixed  $action
     * @return array{0: int, 1: int} done, skipped
     */
    private function eachArchivable(callable $action): array
    {
        $done = 0;
        $skipped = 0;

        foreach ($this->selectedLeads() as $lead) {
            if (! $this->user()->can('archive', $lead)) {
                $skipped++;

                continue;
            }

            $action($lead);
            $done++;
        }

        $this->selected = [];

        return [$done, $skipped];
    }

    public function export(): BinaryFileResponse
    {
        $this->authorize('export', Lead::class);

        Audit::log('leads.exported', null, ['tab' => $this->tab, 'count' => $this->query()->count()], 'Leads exported to CSV', brand: $this->brand, logName: 'leads');

        return Excel::download(new LeadsExport($this->query()->reorder('leads.created_at', 'desc')), 'leads-'.$this->brand->slug.'-'.now()->format('Y-m-d').'.csv', \Maatwebsite\Excel\Excel::CSV);
    }

    // ---------------------------------------------------------------- render

    public function render(): View
    {
        $user = $this->user();
        $agent = $this->isAgent();
        $team = $this->seesTeam();
        $visible = fn (): Builder => Lead::query()->visibleTo($user);

        $leads = $this->query()
            ->with(['status', 'department', 'assignee'])
            ->paginate($this->perPage);

        LeadSites::attach($leads->getCollection());

        $visibility = app(ContactVisibility::class);
        $statuses = LeadStatus::query()->ordered()->get();
        $departmentOptions = $this->departmentOptions();
        $agentOptions = app(AssignableAgents::class)->for($user, $this->brand);
        $chips = $this->chips($statuses, $departmentOptions, $agentOptions);

        return view('leads::livewire.app.leads-index', [
            'leads' => $leads,
            'agent' => $agent,
            // Front sales: the team's leads, with a Take button on the free ones.
            'team' => $team,
            'isAdmin' => $user->isGlobalAdmin(),
            'tabs' => $this->tabs($visible, $agent, $team),
            'statuses' => $statuses,
            'sources' => LeadSource::cases(),
            'departmentOptions' => $departmentOptions,
            'sendOptions' => $this->sendOptions(),
            'agentOptions' => $agentOptions,
            'chips' => $chips,
            'currentOrder' => $this->sort !== '' ? '' : $this->currentOrder(),
            'previewLead' => $this->previewLead($user),
            'fullContact' => $leads->mapWithKeys(fn (Lead $lead): array => [$lead->getKey() => $visibility->canSeeFull($user, $lead)])->all(),
            // The Owner cell opens the assign menu for whoever may assign that lead.
            'canAssign' => $agent ? [] : $this->assignable($leads->getCollection(), $user),
            'people' => $agent ? [] : app(AssignableAgents::class)->people($user, $this->brand),
            'teamChoices' => $user->can('distribute', Lead::class)
                ? Department::query()->orderBy('name')->get()->reject(fn (Department $department): bool => $department->isUpsell())->map(fn (Department $department): array => ['id' => (int) $department->getKey(), 'name' => $department->name, 'color' => $department->color ?? '#FF6B2C'])->values()->all()
                : [],
            'can' => [
                'create' => $user->can('create', Lead::class),
                'import' => $user->can('import', Lead::class),
                'export' => $user->can('export', Lead::class),
                'distribute' => $user->can('distribute', Lead::class),
                'deleteAny' => $user->can('deleteAny', Lead::class),
                'restoreAny' => $user->can('restoreAny', Lead::class),
                'archiveAny' => $user->can('archiveAny', Lead::class),
            ],
            'archivedView' => $this->archivedView(),
            'hasFilters' => $chips !== [],
        ]);
    }

    /**
     * The filters in use, in words, one chip each.
     *
     * @param  Collection<int, LeadStatus>  $statuses
     * @param  array<int, string>  $departments
     * @param  array<int, string>  $agents
     * @return array<string, string>
     */
    private function chips(Collection $statuses, array $departments, array $agents): array
    {
        $day = fn (string $date): string => rescue(fn (): string => CarbonImmutable::parse($date)->format('j M Y'), $date, false);

        return array_filter([
            'status' => $this->status !== '' ? 'Status: '.($statuses->firstWhere('id', (int) $this->status)->name ?? 'unknown') : null,
            'source' => $this->source !== '' ? 'Source: '.(LeadSource::tryFrom($this->source)?->getLabel() ?? $this->source) : null,
            'department' => $this->department !== '' ? 'Department: '.($departments[(int) $this->department] ?? 'unknown') : null,
            'owner' => $this->owner !== '' ? 'Owner: '.($agents[(int) $this->owner] ?? 'unknown') : null,
            'assigned' => match ($this->assigned) {
                'yes' => 'Assigned only',
                'no' => 'Unassigned only',
                default => null,
            },
            'from' => $this->from !== '' ? 'From '.$day($this->from) : null,
            'until' => $this->until !== '' ? 'Until '.$day($this->until) : null,
        ]);
    }

    /** @return Builder<Lead> */
    private function query(): Builder
    {
        $user = $this->user();
        $like = '%'.$this->search.'%';

        $query = Lead::query()->visibleTo($user);

        match ($this->tab) {
            'new' => $query->whereHas('status', fn (Builder $status): Builder => $status->where('is_default', true)),
            'unassigned' => $query->unassigned(),
            'mine' => $this->isAgent() ? $query->assignedTo($user)->open() : $query->assignedTo($user),
            // Follow-ups are on the leads you work, not a colleague's.
            'follow-ups' => $query->workedBy($user)->open()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', ViewerTime::endOfToday()),
            'won' => $query->whereHas('status', fn (Builder $status): Builder => $status->where('is_won', true)),
            default => null,
        };

        // Archived leads are out of every view but their own, which is for those who may archive.
        if ($this->archivedView()) {
            $query->archived();
        } else {
            $query->unarchived();
        }

        $query
            ->when($this->search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $w): Builder => $this->searchContacts($w->where('name', 'like', $like)->when(ProfileNames::ready(), fn (Builder $n): Builder => $n->orWhere('profile_name', 'like', $like)))))
            ->when($this->status !== '', fn (Builder $q): Builder => $q->where('status_id', (int) $this->status))
            ->when($this->source !== '', fn (Builder $q): Builder => $q->where('source_type', $this->source))
            ->when($this->department !== '', fn (Builder $q): Builder => $q->where('department_id', (int) $this->department))
            ->when($this->owner !== '', fn (Builder $q): Builder => $q->where('assigned_to', (int) $this->owner))
            ->when($this->assigned === 'yes', fn (Builder $q): Builder => $q->whereNotNull('assigned_to'))
            ->when($this->assigned === 'no', fn (Builder $q): Builder => $q->whereNull('assigned_to'))
            ->when($this->from !== '', fn (Builder $q): Builder => $q->where('created_at', '>=', $this->from))
            ->when($this->until !== '', fn (Builder $q): Builder => $q->where('created_at', '<=', $this->until.' 23:59:59'));

        [, $column, $direction] = self::ORDERS[$this->currentOrder()];

        // A column heading, when one was clicked, decides instead.
        if ($this->sort !== '') {
            $column = $this->sortColumn(['name', 'created_at', 'next_follow_up_at', 'last_activity_at', 'status_id'], $column);
            $direction = $this->sortDirection();
        }

        return $query->orderBy($column, $direction)->orderBy('id', 'desc');
    }

    /** The Sort choice in force: the one picked, else newest first (the next follow-up on Follow-ups). */
    private function currentOrder(): string
    {
        if (array_key_exists($this->order, self::ORDERS)) {
            return $this->order;
        }

        return $this->tab === 'follow-ups' ? 'follow-up' : 'newest';
    }

    /**
     * @param  callable(): Builder<Lead>  $visible
     * @return array<string, array{label: string, count: ?int, color: string}>
     */
    private function tabs(callable $visible, bool $agent, bool $team): array
    {
        $count = fn (int $n): ?int => $n > 0 ? $n : null;
        $user = $this->user();

        $tabs = [
            'all' => ['label' => 'All', 'count' => null, 'color' => 'gray'],
            'new' => ['label' => 'New', 'count' => $count($visible()->unarchived()->whereHas('status', fn (Builder $s): Builder => $s->where('is_default', true))->count()), 'color' => 'gray'],
        ];

        // Front sales sees the free leads too: they are the ones to take.
        if (! $agent || $team) {
            $tabs['unassigned'] = ['label' => 'Unassigned', 'count' => $count($visible()->unassigned()->count()), 'color' => 'primary'];
        }

        $tabs['mine'] = ['label' => $agent && ! $team ? 'Open' : 'Mine', 'count' => null, 'color' => 'gray'];
        $tabs['follow-ups'] = ['label' => 'Follow-ups today', 'count' => $count($visible()->unarchived()->workedBy($user)->open()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', ViewerTime::endOfToday())->count()), 'color' => 'danger'];
        $tabs['won'] = ['label' => 'Won', 'count' => null, 'color' => 'gray'];

        // The Archived view opens from its button at the top, like the Bin, and shows here while it is open.
        if ($this->archivedView()) {
            $tabs['archived'] = ['label' => 'Archived', 'count' => null, 'color' => 'gray'];
        }

        return $tabs;
    }

    /** @return Collection<int, Lead> */
    private function selectedLeads(): Collection
    {
        return Lead::query()->visibleTo($this->user())->whereKey($this->selected)->get();
    }

    /**
     * Which of these leads the person may assign. The answer depends on the
     * lead's department alone, so the policy is asked once per department,
     * not once per row.
     *
     * @param  iterable<int, Lead>  $leads
     * @return array<int, bool>
     */
    private function assignable(iterable $leads, User $user): array
    {
        $byDepartment = [];
        $may = [];

        foreach ($leads as $lead) {
            $may[(int) $lead->getKey()] = $byDepartment[$lead->department_id ?? 0] ??= $user->can('assign', $lead);
        }

        return $may;
    }

    /** @return array<int, string> */
    private function departmentOptions(): array
    {
        $user = $this->user();
        $query = Department::query()->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereKey($user->managedDepartmentIds($this->brand));
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * Where leads can be sent: the same departments, without the Upsell teams (they look after clients, not leads).
     *
     * @return array<int, string>
     */
    private function sendOptions(): array
    {
        $upsell = Department::query()->whereKey(array_keys($this->departmentOptions()))->get()->filter(fn (Department $department): bool => $department->isUpsell())->modelKeys();

        return array_diff_key($this->departmentOptions(), array_flip($upsell));
    }

    private function isAgent(): bool
    {
        $user = $this->user();

        return ! $user->isGlobalAdmin() && $user->isAgentIn($this->brand);
    }

    private function archivedView(): bool
    {
        return $this->tab === 'archived' && $this->user()->can('archiveAny', Lead::class);
    }

    /** An Agent on a front sales team: sees the team's leads and takes the free ones. */
    private function seesTeam(): bool
    {
        return $this->isAgent() && $this->user()->frontDepartmentIds($this->brand) !== [];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * Email and phone are searched by any part for those who see them in full;
     * for somebody who sees them masked (an Agent) only the whole address or
     * number matches, so typed fragments cannot uncover a masked contact.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function searchContacts(Builder $query): Builder
    {
        $user = auth()->user();
        $full = $user instanceof User && ($user->isGlobalAdmin() || $user->can(Permission::LeadsRevealContact->value));
        $term = trim($this->search);

        return $full
            ? $query->orWhere('email', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%')
            : $query->orWhere('email', mb_strtolower($term))->orWhere('phone', $term);
    }
}
