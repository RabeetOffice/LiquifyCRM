<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Sales\Actions\AssignClient;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Enums\ClientStage;
use App\Modules\Sales\Enums\SaleKind;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\CareTeams;
use App\Modules\Sales\Support\ClientCare;
use App\Modules\Sales\Support\SalePayments;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Clients of the brand: what they are worth together, then each one with
 * who looks after them. The views follow a client's way after the first
 * sale (not handed over, waiting for a person, active, closed); a Manager or
 * an Admin ticks clients here and gives them to a person or a team.
 */
#[Layout('components.layouts.app')]
#[Title('Clients')]
final class ClientsIndex extends Component
{
    use AuthorizesRequests;
    use WithTableState;
    use WithToasts;

    /** The orders offered beside the search: column and direction. */
    public const ORDERS = [
        'value' => ['lifetime_value', 'desc', 'Highest value'],
        'recent' => ['last_sale_at', 'desc', 'Latest sale'],
        'sales' => ['sales_count', 'desc', 'Most sales'],
        'name' => ['name', 'asc', 'A to Z'],
    ];

    public Brand $brand;

    /** open, mine, waiting, front or closed. */
    #[Url(as: 'view', except: 'open')]
    public string $tab = 'open';

    /** @var list<int> */
    public array $selected = [];

    public bool $showGive = false;

    public string $giveTo = '';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Client::class);
    }

    /** The list behind redraws with the new clients; the import stays open for the workbook's next tab. */
    #[On('clients-imported')]
    public function clientsImported(): void
    {
        $this->resetPage();
    }

    public function updatedTab(): void
    {
        $this->selected = [];
        $this->resetPage();
    }

    public function order(string $order): void
    {
        if (! array_key_exists($order, self::ORDERS)) {
            return;
        }

        [$this->sort, $this->direction] = self::ORDERS[$order];
        $this->resetPage();
    }

    public function openGive(): void
    {
        abort_unless($this->canGive(), 403);

        $this->giveTo = '';
        $this->resetErrorBag();
        $this->showGive = true;
    }

    /** Give the ticked clients to a person, or to a team to decide (round robin shares them in turn). */
    public function give(): void
    {
        abort_unless($this->canGive(), 403);

        $this->validate(['giveTo' => ['required', 'string']], ['giveTo.required' => 'Choose a team or a person.']);

        $choice = CareTeams::parse($this->brand, $this->giveTo);

        if ($choice === null) {
            $this->addError('giveTo', 'Choose a team or a person from the list.');

            return;
        }

        [$department, $person] = $choice;
        $user = $this->user();
        $given = 0;

        foreach (Client::query()->visibleTo($user)->whereKey($this->selected)->get() as $client) {
            if ($client->isClosed()) {
                continue;
            }

            try {
                // The same team and a person named: only who looks after them changes.
                if ($person !== null && $client->department_id === $department->getKey()) {
                    if (! $user->can('assign', $client)) {
                        continue;
                    }

                    app(AssignClient::class)->handle($client, $person, $user);
                } else {
                    if (! $user->can('handOver', $client)) {
                        continue;
                    }

                    app(HandOverClient::class)->handle($client, $department, $person, $user);
                }

                $given++;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        $skipped = count($this->selected) - $given;
        $this->selected = [];
        $this->showGive = false;

        $given > 0
            ? $this->toastSuccess($given.' '.str('client')->plural($given).' given to '.($person->name ?? $department->name), $skipped > 0 ? $skipped.' left as they were (closed, or not yours to move).' : null)
            : $this->toastWarning('Nothing changed', 'Those clients are closed or not yours to move.');
    }

    public function render(): View
    {
        $user = $this->user();
        $ready = ClientCare::ready();
        $like = '%'.$this->search.'%';
        $tabs = $this->tabs($user, $ready);
        $tab = array_key_exists($this->tab, $tabs) ? $this->tab : 'open';

        $visible = fn (): Builder => Client::query()->where('sales_count', '>', 0)->visibleTo($user);

        // Everything above the list counts the clients still open; the Closed view counts those.
        $scope = fn (): Builder => $visible()->when($ready, fn (Builder $query): Builder => $tab === 'closed'
            ? $query->inStage(ClientStage::Closed)
            : $query->whereNull('clients.closed_at'));

        $column = $this->sortColumn(['name', 'lifetime_value', 'sales_count', 'last_sale_at', 'first_sale_at'], 'lifetime_value');
        $direction = $this->sortDirection('desc');

        $clients = $scope()
            ->when($ready && $tab === 'mine', fn (Builder $query): Builder => $query->where('owner_id', $user->getKey()))
            ->when($ready && $tab === 'waiting', fn (Builder $query): Builder => $query->inStage(ClientStage::Waiting))
            ->when($ready && $tab === 'front', fn (Builder $query): Builder => $query->inStage(ClientStage::Front))
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)))
            ->when($ready, fn (Builder $query): Builder => $query->with(['owner', 'department'])->withCount('upsells'))
            ->orderBy($column, $direction)
            ->paginate($this->perPage);

        // Front sales must not see what Upsell sells: on a client this person is not on, the money, the sales and
        // the upsells shown are only what their own team sold, as on the client's own page. Admins and the client's
        // team see everything.
        $careIds = $user->isGlobalAdmin() ? null : ($ready ? $scope()->inCareOf($user)->toBase()->pluck('clients.id')->map(fn (mixed $id): int => (int) $id)->all() : []);
        $ownSales = fn (array $ids) => $ids === [] ? collect() : Sale::query()->visibleTo($user)->whereIn('client_id', $ids)
            ->selectRaw('client_id, COALESCE(SUM(amount), 0) as value, COUNT(*) as sales')->groupBy('client_id')->get()->keyBy('client_id');

        $totals = $scope()->toBase()
            ->when($careIds !== null, fn ($query) => $query->whereIn('clients.id', $careIds === [] ? [0] : $careIds))
            ->selectRaw('COUNT(*) as clients, COALESCE(SUM(lifetime_value), 0) as value, COALESCE(MAX(lifetime_value), 0) as top, COALESCE(SUM(CASE WHEN sales_count > 1 THEN 1 ELSE 0 END), 0) as came_back')
            ->first();

        $count = (int) ($totals->clients ?? 0);
        $shown = [];

        if ($careIds !== null) {
            $all = $scope()->toBase()->pluck('clients.id')->map(fn (mixed $id): int => (int) $id)->all();
            $others = $ownSales(array_values(array_diff($all, $careIds)));
            $count = count($all);
            $totals = (object) [
                'clients' => $count,
                'value' => (float) ($totals->value ?? 0) + (float) $others->sum('value'),
                'top' => max((float) ($totals->top ?? 0), (float) ($others->max('value') ?? 0)),
                'came_back' => (int) ($totals->came_back ?? 0) + $others->filter(fn ($row): bool => (int) $row->sales > 1)->count(),
            ];

            // The rows of this page that are not theirs show their own team's part.
            $notTheirs = array_values(array_diff(collect($clients->items())->map(fn (Client $client): int => (int) $client->getKey())->all(), $careIds));
            $onPage = ($ownSales)($notTheirs);

            foreach ($notTheirs as $clientId) {
                $row = $onPage->get($clientId);
                $shown[$clientId] = ['value' => (float) ($row->value ?? 0), 'sales' => (int) ($row->sales ?? 0)];
            }
        }

        // Part payments: what is still to collect, by the same rule as the money above (all of it on a client this
        // person is on, their own team's sales on the others).
        $owed = 'COALESCE(SUM(GREATEST(amount - COALESCE(amount_paid, amount), 0)), 0)';
        $dues = [];
        $dueTotal = 0.0;

        if (SalePayments::ready()) {
            $pageIds = collect($clients->items())->map(fn (Client $client): int => (int) $client->getKey())->all();
            $theirs = $careIds === null ? $pageIds : array_values(array_intersect($pageIds, $careIds));
            $byClient = fn (Builder $sales): array => $sales->toBase()->selectRaw('client_id, '.$owed.' as due')->groupBy('client_id')->pluck('due', 'client_id')->all();

            $others = array_values(array_diff($pageIds, $theirs));
            // Keyed by client id; the two sets never share a client.
            $rows = ($theirs === [] ? [] : $byClient(Sale::query()->whereIn('client_id', $theirs)))
                + ($others === [] ? [] : $byClient(Sale::query()->visibleTo($user)->whereIn('client_id', $others)));

            foreach ($rows as $clientId => $due) {
                $dues[(int) $clientId] = (float) $due;
            }

            $sumOf = fn (Builder $sales): float => (float) $sales->toBase()->selectRaw($owed.' as due')->value('due');
            $dueTotal = $careIds === null
                ? $sumOf(Sale::query()->whereIn('client_id', $scope()->select('clients.id')))
                : $sumOf(Sale::query()->whereIn('client_id', $careIds === [] ? [0] : $careIds))
                    + $sumOf(Sale::query()->visibleTo($user)->whereIn('client_id', array_values(array_diff($scope()->toBase()->pluck('clients.id')->map(fn (mixed $id): int => (int) $id)->all(), $careIds)) ?: [0]));
        }

        return view('sales::livewire.app.clients-index', [
            'ready' => $ready,
            'clients' => $clients,
            'any' => $ready ? $visible()->exists() : $count > 0,
            'summary' => [
                'clients' => $count,
                'value' => (float) ($totals->value ?? 0),
                'average' => $count > 0 ? (float) $totals->value / $count : 0.0,
                'returning' => (int) ($totals->came_back ?? 0),
                'top' => (float) ($totals->top ?? 0),
                'due' => $dueTotal,
                'upsell' => $ready ? (float) Sale::query()->where('kind', SaleKind::Upsell->value)->whereIn('client_id', $careIds === null ? $scope()->select('clients.id') : ($careIds === [] ? [0] : $careIds))->sum('amount') : 0.0,
            ],
            // client id => what is still to collect on it, as this person sees it
            'dues' => $dues,
            'paymentsReady' => SalePayments::ready(),
            // client id => what this person may see of a client they are not on
            'shown' => $shown,
            'tabs' => $tabs,
            'tab' => $tab,
            'counts' => $ready ? [
                'waiting' => array_key_exists('waiting', $tabs) ? $visible()->inStage(ClientStage::Waiting)->count() : 0,
                'front' => array_key_exists('front', $tabs) ? $visible()->inStage(ClientStage::Front)->count() : 0,
            ] : ['waiting' => 0, 'front' => 0],
            'canImport' => $user->can('import', Client::class),
            'orders' => self::ORDERS,
            'order' => collect(self::ORDERS)->search(fn (array $order): bool => $order[0] === $column && $order[1] === $direction) ?: null,
            'canGive' => $ready && $this->canGive(),
            'teams' => $ready && $this->canGive() ? CareTeams::options($this->brand) : [],
            'mineOnly' => $ready && ! $this->canGive(),
        ]);
    }

    /**
     * The views a person gets. Someone who only looks after clients has no
     * queue to work through, so they see theirs and the closed ones.
     *
     * @return array<string, string>
     */
    private function tabs(User $user, bool $ready): array
    {
        if (! $ready) {
            return ['open' => 'All'];
        }

        if (! $this->canGive()) {
            return ['open' => 'My clients', 'closed' => 'Closed'];
        }

        return [
            'open' => 'All',
            ...($user->isGlobalAdmin() ? [] : ['mine' => 'Mine']),
            'waiting' => 'Waiting for a person',
            'front' => 'Not handed over',
            'closed' => 'Closed',
        ];
    }

    /** Admins and Managers hand clients out; everyone else works the ones they were given. */
    private function canGive(): bool
    {
        $user = $this->user();

        return $user->isGlobalAdmin() || $user->isManagerIn($this->brand);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
