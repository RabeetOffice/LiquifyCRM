<?php

declare(strict_types=1);

namespace App\Modules\Leads\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\BrandRole;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Support\Audit;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Actions\SendLeadToDepartment;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Exports\LeadsExport;
use App\Modules\Leads\Livewire\Concerns\HandlesLeadUndo;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Support\ContactVisibility;
use App\Modules\Sales\Models\LeadDistribution;
use App\Modules\Sites\Support\LeadSites;
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
 * Won), filters, search, bulk assign / send / status / delete, CSV import
 * and export, and the lead slide-over.
 */
#[Layout('components.layouts.app')]
#[Title('Leads')]
final class LeadsIndex extends Component
{
    use AuthorizesRequests;
    use HandlesLeadUndo;
    use WithConfirmation;
    use WithTableState;

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

    public ?int $open = null;

    public bool $showBulkAssign = false;

    public string $bulkAssignTo = '';

    public bool $showBulkSend = false;

    public string $bulkSendTo = '';

    public bool $showBulkStatus = false;

    public string $bulkStatusTo = '';

    public bool $showImport = false;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Lead::class);

        if (request()->query('open')) {
            $this->open = (int) request()->query('open');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'status', 'source', 'department', 'owner', 'assigned', 'from', 'until'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'source', 'department', 'owner', 'assigned', 'from', 'until', 'search');
        $this->resetPage();
    }

    public function openLead(int $leadId): void
    {
        $this->open = $leadId;
    }

    public function closeLead(): void
    {
        $this->open = null;
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

    #[On('leads-imported')]
    public function leadsImported(): void
    {
        $this->showImport = false;
    }

    // ------------------------------------------------------------------ bulk

    public function bulkAssign(): void
    {
        $this->validate(['bulkAssignTo' => ['required', 'integer']]);

        $agent = User::query()->findOrFail((int) $this->bulkAssignTo);
        $done = 0;

        foreach ($this->selectedLeads() as $lead) {
            if ($this->user()->can('assign', $lead)) {
                app(AssignLead::class)->handle($lead, $agent, $this->user());
                $done++;
            }
        }

        if ($done > 1) {
            LeadDistribution::query()->create(['brand_id' => $this->brand->getKey(), 'from_user_id' => $this->user()->getKey(), 'to_type' => 'user', 'to_id' => $agent->getKey(), 'lead_count' => $done, 'method' => 'manual']);
        }

        $this->showBulkAssign = false;
        $this->selected = [];
        $this->toastSuccess("{$done} ".str('lead')->plural($done).' assigned to '.$agent->name);
    }

    public function bulkSend(): void
    {
        $this->authorize('distribute', Lead::class);
        $this->validate(['bulkSendTo' => ['required', 'integer']]);

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

        foreach ($this->selectedLeads() as $lead) {
            if ($this->user()->can('update', $lead)) {
                app(ChangeLeadStatus::class)->handle($lead, $status, $this->user());
                $done++;
            }
        }

        $this->showBulkStatus = false;
        $this->selected = [];
        $this->toastSuccess("{$done} ".str('lead')->plural($done).' moved to '.$status->name);
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
        $this->toastSuccess($leads->count().' '.str('lead')->plural($leads->count()).' deleted');
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
        $visible = fn (): Builder => Lead::query()->visibleTo($user);

        $leads = $this->query()
            ->with(['status', 'department', 'assignee'])
            ->paginate($this->perPage);

        LeadSites::attach($leads->getCollection());

        $visibility = app(ContactVisibility::class);

        return view('leads::livewire.app.leads-index', [
            'leads' => $leads,
            'agent' => $agent,
            'tabs' => $this->tabs($visible, $agent),
            'statuses' => LeadStatus::query()->ordered()->get(),
            'sources' => LeadSource::cases(),
            'departmentOptions' => $this->departmentOptions(),
            'agentOptions' => $this->agentOptions(),
            'fullContact' => $leads->mapWithKeys(fn (Lead $lead): array => [$lead->getKey() => $visibility->canSeeFull($user, $lead)])->all(),
            'can' => [
                'create' => $user->can('create', Lead::class),
                'import' => $user->can('import', Lead::class),
                'export' => $user->can('export', Lead::class),
                'distribute' => $user->can('distribute', Lead::class),
                'deleteAny' => $user->can('deleteAny', Lead::class),
            ],
            'hasFilters' => $this->status !== '' || $this->source !== '' || $this->department !== '' || $this->owner !== '' || $this->assigned !== '' || $this->from !== '' || $this->until !== '',
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
            'mine' => $this->isAgent() ? $query->open() : $query->assignedTo($user),
            'follow-ups' => $query->open()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now()->endOfDay()),
            'won' => $query->whereHas('status', fn (Builder $status): Builder => $status->where('is_won', true)),
            default => null,
        };

        $query
            ->when($this->search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $w): Builder => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)))
            ->when($this->status !== '', fn (Builder $q): Builder => $q->where('status_id', (int) $this->status))
            ->when($this->source !== '', fn (Builder $q): Builder => $q->where('source_type', $this->source))
            ->when($this->department !== '', fn (Builder $q): Builder => $q->where('department_id', (int) $this->department))
            ->when($this->owner !== '', fn (Builder $q): Builder => $q->where('assigned_to', (int) $this->owner))
            ->when($this->assigned === 'yes', fn (Builder $q): Builder => $q->whereNotNull('assigned_to'))
            ->when($this->assigned === 'no', fn (Builder $q): Builder => $q->whereNull('assigned_to'))
            ->when($this->from !== '', fn (Builder $q): Builder => $q->where('created_at', '>=', $this->from))
            ->when($this->until !== '', fn (Builder $q): Builder => $q->where('created_at', '<=', $this->until.' 23:59:59'));

        $defaultSort = match ($this->tab) {
            'unassigned' => ['created_at', 'asc'],
            'follow-ups' => ['next_follow_up_at', 'asc'],
            default => ['created_at', 'desc'],
        };

        $column = $this->sortColumn(['name', 'created_at', 'next_follow_up_at', 'last_activity_at', 'status_id'], $defaultSort[0]);
        $direction = $this->sort === '' ? $defaultSort[1] : $this->sortDirection();

        return $query->orderBy($column, $direction)->orderBy('id', 'desc');
    }

    /**
     * @param  callable(): Builder<Lead>  $visible
     * @return array<string, array{label: string, count: ?int, color: string}>
     */
    private function tabs(callable $visible, bool $agent): array
    {
        $count = fn (int $n): ?int => $n > 0 ? $n : null;

        $tabs = [
            'all' => ['label' => 'All', 'count' => null, 'color' => 'gray'],
            'new' => ['label' => 'New', 'count' => $count($visible()->whereHas('status', fn (Builder $s): Builder => $s->where('is_default', true))->count()), 'color' => 'gray'],
        ];

        if (! $agent) {
            $tabs['unassigned'] = ['label' => 'Unassigned', 'count' => $count($visible()->unassigned()->count()), 'color' => 'primary'];
        }

        $tabs['mine'] = ['label' => $agent ? 'Open' : 'Mine', 'count' => null, 'color' => 'gray'];
        $tabs['follow-ups'] = ['label' => 'Follow-ups today', 'count' => $count($visible()->open()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now()->endOfDay())->count()), 'color' => 'danger'];
        $tabs['won'] = ['label' => 'Won', 'count' => null, 'color' => 'gray'];

        return $tabs;
    }

    /** @return Collection<int, Lead> */
    private function selectedLeads(): Collection
    {
        return Lead::query()->visibleTo($this->user())->whereKey($this->selected)->get();
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

    /** @return array<int, string> */
    private function agentOptions(): array
    {
        $user = $this->user();

        $query = User::query()
            ->whereHas('memberships', fn (Builder $query): Builder => $query->acrossBrands()->where('brand_id', $this->brand->getKey())->where('role', BrandRole::Agent->value)->where('status', 'active'))
            ->orderBy('name');

        if (! $user->isGlobalAdmin()) {
            $query->whereHas('departments', fn (Builder $query): Builder => $query->acrossBrands()->whereIn('departments.id', $user->managedDepartmentIds($this->brand)));
        }

        return $query->pluck('name', 'id')->all();
    }

    private function isAgent(): bool
    {
        $user = $this->user();

        return ! $user->isGlobalAdmin() && $user->isAgentIn($this->brand);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
