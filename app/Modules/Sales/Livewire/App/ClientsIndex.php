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

/** Clients of the brand by lifetime value. */
#[Layout('components.layouts.app')]
#[Title('Clients')]
final class ClientsIndex extends Component
{
    use AuthorizesRequests;
    use WithTableState;

    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        $this->authorize('viewAny', Client::class);
    }

    public function render(): View
    {
        $user = auth()->user();
        $like = '%'.$this->search.'%';

        $clients = Client::query()
            ->where('sales_count', '>', 0)
            ->when(! $user->isGlobalAdmin(), fn (Builder $query): Builder => $query->whereIn('id', Sale::query()->visibleTo($user)->select('client_id')))
            ->when($this->search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $inner): Builder => $inner
                ->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)))
            ->orderBy($this->sortColumn(['name', 'lifetime_value', 'sales_count', 'last_sale_at', 'first_sale_at'], 'lifetime_value'), $this->sortDirection('desc'))
            ->paginate($this->perPage);

        return view('sales::livewire.app.clients-index', ['clients' => $clients]);
    }
}
