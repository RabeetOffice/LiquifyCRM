<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Client;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** One client: every sale (and who closed it) and every lead. */
#[Layout('components.layouts.app')]
final class ClientShow extends Component
{
    use AuthorizesRequests;

    public Brand $brand;

    public Client $client;

    public function mount(Brand $brand, Client $client): void
    {
        $this->brand = $brand;
        $this->client = $client;
        $this->authorize('view', $client);
    }

    public function render(): View
    {
        return view('sales::livewire.app.client-show', [
            'sales' => $this->client->sales()->with(['closer', 'department'])->get(),
            'leads' => Lead::query()->visibleTo(auth()->user())->where('client_id', $this->client->getKey())->with(['status', 'assignee'])->latest('id')->get(),
        ])->title($this->client->name);
    }
}
