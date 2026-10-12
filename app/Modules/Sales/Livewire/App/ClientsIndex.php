<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithTableState;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Clients of the brand: what they are worth together, then each one by
 * lifetime value, latest sale, number of sales or name.
 */
#[Layout('components.layouts.app')]
#[Title('Clients')]
final class ClientsIndex extends Component
{
    use AuthorizesRequests;
    use WithTableState;

    /** The orders offered as views on top of the list: column and direction. */
    public const ORDERS = [
        'value' => ['lifetime_value', 'desc', 'Highest value'],
        'recent' => ['last_sale_at', 'desc', 'Latest sale'],
        'sales' => ['sales_count', 'desc', 'Most sales'],
        'name' => ['name', 'asc', 'A to Z'],
    ];

    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Client::class);
    }

    public function order(string $order): void
    {
        if (! array_key_exists($order, self::ORDERS)) {
            return;
        }

        [$this->sort, $this->direction] = self::ORDERS[$order];
        $this->resetPage();
    }

    public function render(): View
    {
        $user = auth()->user();
        $like = '%'.$this->search.'%';

        $visible = fn (): Builder => Client::query()
            ->where('sales_count', '>', 0)
            ->when(! $user->isGlobalAdmin(), fn (Builder $query): Builder => $query->whereIn('id', Sale::query()->visibleTo($user)->select('client_id')));

        $column = $this->sortColumn(['name', 'lifetime_value', 'sales_count', 'last_sale_at', 'first_sale_at'], 'lifetime_value');
        $direction = $this->sortDirection('desc');

        $clients = $visible()
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)))
            ->orderBy($column, $direction)
            ->paginate($this->perPage);

        $totals = $visible()->toBase()
            ->selectRaw('COUNT(*) as clients, COALESCE(SUM(lifetime_value), 0) as value, COALESCE(MAX(lifetime_value), 0) as top, COALESCE(SUM(CASE WHEN sales_count > 1 THEN 1 ELSE 0 END), 0) as came_back')
            ->first();

        $count = (int) ($totals->clients ?? 0);

        return view('sales::livewire.app.clients-index', [
            'clients' => $clients,
            'summary' => [
                'clients' => $count,
                'value' => (float) ($totals->value ?? 0),
                'average' => $count > 0 ? (float) $totals->value / $count : 0.0,
                'returning' => (int) ($totals->came_back ?? 0),
                'top' => (float) ($totals->top ?? 0),
            ],
            'orders' => self::ORDERS,
            'order' => collect(self::ORDERS)->search(fn (array $order): bool => $order[0] === $column && $order[1] === $direction) ?: null,
        ]);
    }
}
