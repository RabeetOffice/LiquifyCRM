<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Enums\AssignmentMode;
use App\Modules\Core\Enums\Permission;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Core\Support\Audit;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Departments\Models\Department;
use App\Modules\Leads\Actions\AutoAssignLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadRoute;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Sales\Actions\DistributeLeads;
use App\Modules\Sales\Enums\ClientStage;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\LeadDistribution;
use App\Modules\Sales\Support\CareTeams;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\Sites\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Distribute leads. The Admin filters leads and splits them across
 * departments (equally, by percentage or by count); a Manager does the same
 * inside their department, to Agents (equally or by count). On top, each
 * department chooses how its new leads are shared from now on: by hand, or
 * round robin (the next Agent in turn gets each new lead by itself). And the
 * Admin says, for each website and Facebook Page, where its leads go the
 * moment they arrive: a department, or straight to one person.
 */
#[Layout('components.layouts.app')]
#[Title('Distribute leads')]
final class DistributePage extends Component
{
    use AuthorizesRequests;
    use WithToasts;

    /** Never move more than this in one go. */
    private const LIMIT = 5000;

    public Brand $brand;

    public string $source = '';

    public string $status = '';

    public string $from = '';

    public string $until = '';

    /** Manager: which of their departments. Admin: optional "leads already in this department". */
    public string $department = '';

    /** Admin: only leads no department has yet. Manager: only leads no Agent has yet. */
    public bool $onlyWaiting = true;

    public string $method = 'equal';

    /** @var array<int, bool> target id => included */
    public array $include = [];

    /** @var array<int, string> target id => percent or count */
    public array $amounts = [];

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorizeAccess();

        if (! $this->isAdmin()) {
            $this->department = (string) ($this->managedDepartments()->keys()->first() ?? '');
        }
    }

    public function updatedDepartment(): void
    {
        $this->include = [];
        $this->amounts = [];
    }

    /**
     * Where the leads of one website or Facebook Page go by themselves: a
     * department ("12"), one person in it ("12:34"), or nowhere (they wait in
     * the queue). Admins only.
     */
    public function setRoute(string $channel, int $sourceId, string $target): void
    {
        abort_unless($this->isAdmin() && ClientCare::ready(), 403);

        $source = collect($this->sources())->first(fn (array $source): bool => $source['channel'] === $channel && $source['id'] === $sourceId);
        abort_if($source === null, 404);

        $key = ['brand_id' => $this->brand->getKey(), 'channel' => $channel, 'source_id' => $sourceId];

        if ($target === '') {
            LeadRoute::query()->where($key)->delete();
            Audit::log('leads.route_changed', $this->brand, ['channel' => $channel, 'source_id' => $sourceId, 'to' => null], 'Leads from '.$source['name'].' wait in the queue again', brand: $this->brand, logName: 'leads');
            $this->toastSuccess($source['name'].': leads wait in the queue', 'Someone assigns each one by hand.');

            return;
        }

        if (! preg_match('/^(\d+)(?::(\d+))?$/', $target, $parts)) {
            return;
        }

        $department = Department::query()->findOrFail((int) $parts[1]);
        $person = isset($parts[2]) ? CareTeams::people($department)->firstWhere('id', (int) $parts[2]) : null;
        abort_if(isset($parts[2]) && $person === null, 404);

        LeadRoute::query()->updateOrCreate($key, ['department_id' => $department->getKey(), 'user_id' => $person?->getKey(), 'set_by' => $this->user()->getKey()]);

        $to = $person !== null ? $person->name.' in '.$department->name : $department->name;

        Audit::log('leads.route_changed', $this->brand, ['channel' => $channel, 'source_id' => $sourceId, 'department_id' => $department->getKey(), 'user_id' => $person?->getKey()], 'Leads from '.$source['name'].' now go to '.$to, brand: $this->brand, department: $department, logName: 'leads');

        $this->toastSuccess($source['name'].': leads go to '.$to, $person !== null ? 'Each new lead is theirs the moment it arrives.' : 'The department shares them its own way.');
    }

    /** A Manager (or an Admin) chooses how a department shares its new leads from now on. */
    public function setSharing(int $departmentId, string $mode): void
    {
        $this->authorizeAccess();

        $department = Department::query()->findOrFail($departmentId);
        abort_unless($this->isAdmin() || $this->managedDepartments()->has($departmentId), 403);

        $mode = AssignmentMode::tryFrom($mode) ?? AssignmentMode::Manual;

        if ($department->assignment_mode === $mode) {
            return;
        }

        $department->update(['assignment_mode' => $mode]);

        Audit::log('departments.sharing_changed', $department, ['mode' => $mode->value], $department->name.' now shares leads '.mb_strtolower($mode->getLabel()), department: $department, logName: 'departments');

        $what = $department->isUpsell() ? 'client' : 'lead';

        $mode === AssignmentMode::RoundRobin
            ? $this->toastSuccess($department->name.': round robin is on', 'Each new '.$what.' goes to the next person in turn.')
            : $this->toastSuccess($department->name.': shared by hand', 'New '.$what.'s wait until someone gives them out.');
    }

    /** Share the leads already waiting in a round-robin department, in the same turn order. */
    public function shareWaiting(int $departmentId, AutoAssignLead $auto): void
    {
        $this->authorizeAccess();

        $department = Department::query()->findOrFail($departmentId);
        abort_unless($this->isAdmin() || $this->managedDepartments()->has($departmentId), 403);

        $leadIds = Lead::query()->open()->where('department_id', $departmentId)->whereNull('assigned_to')->oldest('id')->limit(self::LIMIT)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $shared = $auto->spread($department, $leadIds);

        if ($shared === []) {
            $this->toastWarning('Nothing shared', $leadIds === [] ? 'No leads are waiting in '.$department->name.'.' : 'Turn on round robin and make sure the department has an active Agent.');

            return;
        }

        $total = array_sum($shared);

        DashboardCache::flush((int) $this->brand->getKey());
        Audit::log('leads.distributed', $this->brand, ['to' => 'user', 'method' => 'round_robin', 'department_id' => $departmentId, 'split' => $shared], $total.' waiting leads shared round robin in '.$department->name, brand: $this->brand, department: $department, logName: 'leads');

        $this->toastSuccess($total.' '.str('lead')->plural($total).' shared', 'Among '.count($shared).' '.str('Agent')->plural(count($shared)).' in '.$department->name.'.');
    }

    public function distribute(DistributeLeads $action): void
    {
        $this->authorizeAccess();

        $this->validate([
            'method' => ['required', 'in:'.implode(',', $this->methods())],
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
            'amounts.*' => ['nullable', 'numeric', 'min:0'],
        ], attributes: ['amounts.*' => 'amount']);

        $targets = $this->targets();
        $chosen = array_values(array_filter(array_keys($targets), fn (int $id): bool => (bool) ($this->include[$id] ?? false)));

        if ($chosen === []) {
            $this->addError('include', $this->isAdmin() ? 'Choose at least one department.' : 'Choose at least one Agent.');

            return;
        }

        $leadIds = $this->query()->oldest('id')->limit(self::LIMIT)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($leadIds === []) {
            $this->addError('include', 'No leads match these filters.');

            return;
        }

        try {
            $allocation = DistributeLeads::allocate(count($leadIds), array_combine($chosen, array_map(fn (int $id): float => (float) ($this->amounts[$id] ?? 0), $chosen)), $this->method);
        } catch (InvalidArgumentException $exception) {
            $this->addError('include', $exception->getMessage());

            return;
        }

        if ($allocation === []) {
            $this->addError('include', $this->method === 'percent' ? 'Enter a percentage for each one.' : 'Enter how many leads each one gets.');

            return;
        }

        $moved = $action->handle($this->brand, $this->user(), $leadIds, $this->isAdmin() ? DistributeLeads::TO_DEPARTMENT : DistributeLeads::TO_USER, $allocation, $this->method, $this->filters());

        $this->include = [];
        $this->amounts = [];
        $this->toastSuccess($moved.' '.str('lead')->plural($moved).' distributed', 'Each '.($this->isAdmin() ? 'department' : 'Agent').' has been told.');
    }

    public function render(): View
    {
        $targets = $this->targets();
        $matching = min($this->query()->count(), self::LIMIT);
        $chosen = array_values(array_filter(array_keys($targets), fn (int $id): bool => (bool) ($this->include[$id] ?? false)));

        try {
            $preview = $chosen === [] ? [] : DistributeLeads::allocate($matching, array_combine($chosen, array_map(fn (int $id): float => (float) ($this->amounts[$id] ?? 0), $chosen)), $this->method);
            $previewError = null;
        } catch (InvalidArgumentException $exception) {
            $preview = [];
            $previewError = $exception->getMessage();
        }

        $history = LeadDistribution::query()
            ->with('from')
            ->when(! $this->isAdmin(), fn (Builder $query): Builder => $query->where('from_user_id', $this->user()->getKey()))
            ->latest('id')
            ->limit(15)
            ->get();

        $sharing = Department::query()
            ->when(! $this->isAdmin(), fn (Builder $query): Builder => $query->whereIn('id', $this->managedDepartments()->keys()))
            ->withCount('agents')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => [
                'department' => $department,
                'agents' => (int) $department->getAttribute('agents_count'),
                // An Upsell department receives clients, not leads.
                'waiting' => $department->isUpsell()
                    ? (ClientCare::ready() ? Client::query()->where('department_id', $department->getKey())->inStage(ClientStage::Waiting)->count() : 0)
                    : Lead::query()->open()->where('department_id', $department->getKey())->whereNull('assigned_to')->count(),
            ])->all();

        $sources = $this->isAdmin() && ClientCare::ready() ? $this->sources() : [];
        $routes = $sources !== [] ? LeadRoute::query()->get()->keyBy(fn (LeadRoute $route): string => $route->channel.':'.$route->source_id) : collect();

        return view('sales::livewire.app.distribute-page', [
            'isAdmin' => $this->isAdmin(),
            // A Manager of Upsell departments only has clients to share, no leads to split.
            'clientsOnly' => CareTeams::worksClientsOnly($this->user(), $this->brand),
            'sharing' => $sharing,
            'routeSources' => $sources,
            'routes' => $routes,
            'routeTargets' => $sources !== [] ? $this->routeTargets() : [],
            'modes' => AssignmentMode::cases(),
            'targets' => $targets,
            'matching' => $matching,
            'preview' => $preview,
            'previewError' => $previewError,
            'left' => $matching - array_sum($preview),
            'methods' => $this->methods(),
            'sources' => LeadSource::cases(),
            'statuses' => LeadStatus::query()->ordered()->get(),
            'departments' => $this->isAdmin() ? Department::query()->orderBy('name')->pluck('name', 'id') : $this->managedDepartments(),
            'history' => $history,
            'names' => [
                DistributeLeads::TO_DEPARTMENT => Department::query()->whereIn('id', $history->where('to_type', DistributeLeads::TO_DEPARTMENT)->pluck('to_id'))->pluck('name', 'id'),
                DistributeLeads::TO_USER => User::query()->whereIn('id', $history->where('to_type', DistributeLeads::TO_USER)->pluck('to_id'))->pluck('name', 'id'),
            ],
            'limit' => self::LIMIT,
        ]);
    }

    // -------------------------------------------------------------- internals

    /** @return Builder<Lead> */
    private function query(): Builder
    {
        $departmentId = ctype_digit($this->department) ? (int) $this->department : null;

        $query = Lead::query()
            ->open()
            ->when($this->source !== '', fn (Builder $query): Builder => $query->where('source_type', $this->source))
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status_id', (int) $this->status))
            ->when($this->from !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->until !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $this->until));

        if ($this->isAdmin()) {
            return $query
                ->when($this->onlyWaiting, fn (Builder $query): Builder => $query->whereNull('department_id'))
                ->when(! $this->onlyWaiting && $departmentId !== null, fn (Builder $query): Builder => $query->where('department_id', $departmentId));
        }

        // A Manager only ever moves leads of a department they lead.
        $allowed = $this->managedDepartments()->keys()->all();
        $departmentId = in_array($departmentId, $allowed, true) ? $departmentId : 0;

        return $query
            ->where('department_id', $departmentId)
            ->when($this->onlyWaiting, fn (Builder $query): Builder => $query->whereNull('assigned_to'));
    }

    /**
     * Every source of this brand that can have a rule: its websites and its Facebook Pages.
     *
     * @return list<array{channel: string, id: int, name: string, detail: string, site: ?Site}>
     */
    private function sources(): array
    {
        $sites = Site::query()->orderBy('name')->get()->map(fn (Site $site): array => [
            'channel' => LeadRoute::WEBSITE, 'id' => (int) $site->getKey(), 'name' => $site->name, 'detail' => 'Website · '.$site->domain, 'site' => $site,
        ]);

        $pages = MetaSource::query()->where('brand_id', $this->brand->getKey())->orderBy('name')->get()->map(fn (MetaSource $page): array => [
            'channel' => LeadRoute::FACEBOOK, 'id' => (int) $page->getKey(), 'name' => $page->name, 'detail' => 'Facebook Page · lead forms and Messenger', 'site' => null,
        ]);

        return [...$sites->all(), ...$pages->all()];
    }

    /**
     * Where a source may send its leads: each department that works leads, and each person in it.
     *
     * @return list<array{department: Department, people: array<int, string>}>
     */
    private function routeTargets(): array
    {
        return Department::query()->orderBy('name')->get()
            ->reject(fn (Department $department): bool => $department->isUpsell())
            ->map(fn (Department $department): array => ['department' => $department, 'people' => CareTeams::people($department)->pluck('name', 'id')->all()])
            ->values()
            ->all();
    }

    /** @return array<int, string> target id => name: departments for the Admin, Agents for a Manager */
    private function targets(): array
    {
        if ($this->isAdmin()) {
            // Leads are shared among the departments that work leads; an Upsell department receives clients.
            return Department::query()->orderBy('name')->get()->reject(fn (Department $department): bool => $department->isUpsell())->pluck('name', 'id')->all();
        }

        $department = ctype_digit($this->department) && $this->managedDepartments()->has((int) $this->department)
            ? Department::query()->with(['agents' => fn ($query) => $query->orderBy('name')])->find((int) $this->department)
            : null;

        return $department?->agents->pluck('name', 'id')->all() ?? [];
    }

    /** @return Collection<int, string> */
    private function managedDepartments(): Collection
    {
        return Department::query()->whereIn('id', $this->user()->managedDepartmentIds($this->brand))->orderBy('name')->pluck('name', 'id');
    }

    /** @return list<string> */
    private function methods(): array
    {
        return $this->isAdmin() ? ['equal', 'percent', 'count'] : ['equal', 'count'];
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return array_filter([
            'source' => $this->source,
            'status_id' => $this->status,
            'from' => $this->from,
            'until' => $this->until,
            'department_id' => $this->department,
            'only_waiting' => $this->onlyWaiting,
        ], fn (mixed $value): bool => $value !== '' && $value !== false);
    }

    /** Admins distribute to departments; a Manager to the Agents of the departments they lead. */
    private function authorizeAccess(): void
    {
        $user = $this->user();

        abort_unless($user->isGlobalAdmin() || ($user->isManagerIn($this->brand) && $user->can(Permission::LeadsAssign->value)), 403);
    }

    private function isAdmin(): bool
    {
        return $this->user()->isGlobalAdmin();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
