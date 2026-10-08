<?php

declare(strict_types=1);

namespace App\Modules\Sales\Livewire\App;

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\Concerns\WithConfirmation;
use App\Modules\Core\Livewire\Concerns\WithToasts;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\CorrectSale;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** One client: every sale (and who closed it) and every lead. */
#[Layout('components.layouts.app')]
final class ClientShow extends Component
{
    use AuthorizesRequests;
    use WithConfirmation;
    use WithToasts;

    public Brand $brand;

    public Client $client;

    public function mount(Brand $brand, Client $client): void
    {
        $this->brand = $brand;
        $this->client = $client;
        $this->authorize('view', $client);
    }

    public ?int $editing = null;

    public bool $showEdit = false;

    public string $amount = '';

    public string $service = '';

    public string $closedAt = '';

    /** Admins only: correct the amount, service or date of a recorded sale. */
    public function edit(int $saleId): void
    {
        $sale = $this->sale($saleId);
        $this->authorize('update', $sale);

        $this->editing = $saleId;
        $this->amount = (string) (float) $sale->amount;
        $this->service = (string) $sale->service;
        $this->closedAt = $sale->closed_at->toDateString();
        $this->resetErrorBag();
        $this->showEdit = true;
    }

    public function update(CorrectSale $action): void
    {
        $sale = $this->sale((int) $this->editing);
        $this->authorize('update', $sale);

        $data = $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'service' => ['nullable', 'string', 'max:190'],
            'closedAt' => ['required', 'date', 'before_or_equal:today'],
        ], attributes: ['service' => 'service or package', 'closedAt' => 'close date']);

        $action->update($sale, (float) $data['amount'], $data['service'], CarbonImmutable::parse($data['closedAt'])->setTimeFrom($sale->closed_at), auth()->user());

        $this->showEdit = false;
        $this->editing = null;
        $this->client->refresh();
        $this->toastSuccess('Sale corrected', 'Targets and lifetime value are counted again.');
    }

    public function confirmRemove(int $saleId): void
    {
        $sale = $this->sale($saleId);
        $this->authorize('delete', $sale);

        $this->askConfirmation('remove', [$saleId], 'Remove this sale of '.Money::format($sale->amount, $sale->currency).'?', 'It no longer counts towards any target or this client\'s lifetime value. If it was the lead\'s only sale, the lead is no longer Won.', 'Remove sale', 'danger', password: true);
    }

    public function remove(int $saleId): void
    {
        $sale = $this->sale($saleId);
        $this->authorize('delete', $sale);

        app(CorrectSale::class)->delete($sale, auth()->user());

        $this->client->refresh();
        $this->toastSuccess('Sale removed');
    }

    private function sale(int $saleId): Sale
    {
        return Sale::query()->where('client_id', $this->client->getKey())->findOrFail($saleId);
    }

    public function render(): View
    {
        return view('sales::livewire.app.client-show', [
            'sales' => $this->client->sales()->with(['closer', 'department'])->get(),
            'canCorrect' => (bool) auth()->user()?->isGlobalAdmin(),
            'leads' => Lead::query()->visibleTo(auth()->user())->where('client_id', $this->client->getKey())->with(['status', 'assignee'])->latest('id')->get(),
        ])->title($this->client->name);
    }
}
