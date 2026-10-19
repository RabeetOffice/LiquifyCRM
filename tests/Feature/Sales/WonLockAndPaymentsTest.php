<?php

declare(strict_types=1);

use App\Modules\Leads\Actions\ChangeLeadStatus;
use App\Modules\Leads\Exceptions\LeadIsWon;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Sales\Actions\CorrectSale;
use App\Modules\Sales\Actions\HandOverClient;
use App\Modules\Sales\Actions\RecordPayment;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Livewire\App\ClientsIndex;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\SalesReport;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Two requests of 19 Oct 2026: a lead that is Won with a sale stays in Won
 * (moving it away and winning it again counted the sale twice), and a client
 * may pay part of the deal up front and the rest later.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);
    $this->manager = managerOf($this->front, ['name' => 'Farah Front']);
    $this->seller = agentOf($this->front, ['name' => 'Sami Seller']);
    $this->colleague = agentOf($this->front, ['name' => 'Omar Other']);
    $this->upseller = agentOf($this->upsell, ['name' => 'Usman Upseller']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->seller->getKey(),
        'name' => 'Sarah Lee', 'email' => 'sarah@example.com',
    ]);
    $this->status = fn (string $slug): LeadStatus => LeadStatus::query()->forBrand($this->brand)->where('slug', $slug)->firstOrFail();
});

it('keeps a won lead with a sale in Won, everywhere, so the sale is never counted twice', function (): void {
    app(RecordSale::class)->handle($this->lead, 1500, 'Publishing', now(), $this->seller);
    $proposal = ($this->status)('proposal-sent');

    // The lead's own path and Change status.
    Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertDontSee('wire:click="moveTo(', false)
        ->call('moveTo', $proposal->getKey())
        ->assertDispatched('toast', title: 'This lead stays in Won')
        ->call('openStatus')
        ->assertSet('showStatus', false);

    // The pipeline and the list's bulk status.
    Livewire::actingAs($this->seller)
        ->test(Pipeline::class, ['brand' => $this->brand])
        ->call('move', $this->lead->getKey(), $proposal->getKey())
        ->assertDispatched('toast', title: 'Sarah Lee stays in Won');

    Livewire::actingAs($this->admin)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('selected', [$this->lead->getKey()])
        ->set('bulkStatusTo', (string) ($this->status)('lost')->getKey())
        ->call('bulkStatus')
        ->assertDispatched('toast', title: '0 leads moved to Lost', body: '1 won lead with a sale stayed in Won.');

    expect(fn () => app(ChangeLeadStatus::class)->handle($this->lead->fresh(), $proposal, $this->admin))->toThrow(LeadIsWon::class);

    expect($this->lead->fresh()->status->is_won)->toBeTrue()
        ->and(Sale::query()->count())->toBe(1)
        ->and((float) Client::query()->sole()->lifetime_value)->toBe(1500.0);
});

it('lets an Admin undo a wrong Won by removing the sale, which frees the lead again', function (): void {
    $sale = app(RecordSale::class)->handle($this->lead, 1500, null, now(), $this->seller);

    app(CorrectSale::class)->delete($sale, $this->admin);

    $lead = $this->lead->fresh();

    expect($lead->status->is_won)->toBeFalse()
        ->and($lead->isLockedInWon())->toBeFalse();

    app(ChangeLeadStatus::class)->handle($lead, ($this->status)('contacted'), $this->seller);
    expect($lead->fresh()->status->slug)->toBe('contacted');
});

it('records the whole deal with what was paid up front, and the rest as payments that never count twice', function (): void {
    Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openWon')
        ->assertSee('Paid now')
        ->set('wonAmount', '1500')
        ->set('wonPaid', '2000')
        ->call('recordSale')
        ->assertHasErrors('wonPaid')
        ->set('wonPaid', '500')
        ->call('recordSale')
        ->assertHasNoErrors()
        ->assertSee('£500 paid')
        ->assertSee('£1,000 to collect')
        ->assertSee('Record payment');

    $sale = Sale::query()->sole();

    expect((float) $sale->amount)->toBe(1500.0)
        ->and($sale->paid())->toBe(500.0)
        ->and($sale->due())->toBe(1000.0)
        ->and($sale->payments()->count())->toBe(1);

    // The rest comes in two parts. More than is owed is refused.
    $panel = Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openPayment', $sale->getKey())
        ->assertSet('paymentAmount', '1000.00')
        ->set('paymentAmount', '300')
        ->set('paymentNote', 'Bank transfer')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertDispatched('toast', title: 'Payment recorded: £300', body: '£700 still to collect.')
        ->call('openPayment', $sale->getKey())
        ->set('paymentAmount', '800')
        ->call('recordPayment')
        ->assertHasErrors('paymentAmount')
        ->set('paymentAmount', '700')
        ->call('recordPayment')
        ->assertDispatched('toast', title: 'Payment recorded: £700', body: 'This sale is paid in full now.')
        ->assertSee('Paid in full');

    $sale->refresh();

    expect($sale->due())->toBe(0.0)
        ->and($sale->payments()->pluck('amount')->map(fn ($amount): float => (float) $amount)->all())->toBe([500.0, 300.0, 700.0])
        ->and($this->seller->can('recordPayment', $sale))->toBeFalse()
        // Still one sale of 1,500: the targets, the client and the lead count it once.
        ->and(Sale::query()->count())->toBe(1)
        ->and((float) Client::query()->sole()->lifetime_value)->toBe(1500.0)
        ->and((float) $this->lead->fresh()->value)->toBe(1500.0)
        ->and(SalesReport::forBrand($this->brand, $this->admin, now()->format('Y-m'))->totals()['revenue'])->toBe(1500.0)
        ->and($this->lead->activities()->where('event', 'sales.payment_recorded')->count())->toBe(2);
});

it('lets the seller, their Manager, the client\'s team and Admins record a payment, nobody else', function (): void {
    $sale = app(RecordSale::class)->handle($this->lead, 1500, null, now(), $this->seller, 500);
    $client = Client::query()->sole();

    expect($this->seller->can('recordPayment', $sale))->toBeTrue()
        ->and($this->manager->can('recordPayment', $sale))->toBeTrue()
        ->and($this->admin->can('recordPayment', $sale))->toBeTrue()
        ->and($this->colleague->can('recordPayment', $sale))->toBeFalse()
        ->and($this->upseller->can('recordPayment', $sale))->toBeFalse();

    // Once the client is with the Upsell team, the person looking after them collects the rest.
    app(HandOverClient::class)->handle($client, $this->upsell, $this->upseller, $this->seller);

    expect($this->upseller->fresh()->can('recordPayment', $sale))->toBeTrue();

    Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client->fresh()])
        ->assertSee('£1,000 to collect')
        ->call('openPayment', $sale->getKey())
        ->set('paymentAmount', '1000')
        ->call('recordPayment')
        ->assertHasNoErrors()
        ->assertSee('Paid in full');

    expect(fn () => app(RecordPayment::class)->handle($sale->fresh(), 10, now(), $this->admin))->toThrow(InvalidArgumentException::class, 'This sale is paid in full.');
});

it('takes a part payment with an upsell, and shows what is still to collect on the clients list', function (): void {
    app(RecordSale::class)->handle($this->lead, 1500, null, now(), $this->seller);
    $client = Client::query()->sole();
    app(HandOverClient::class)->handle($client, $this->upsell, $this->upseller, $this->seller);

    Livewire::actingAs($this->upseller)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client->fresh()])
        ->call('openUpsell')
        ->set('upsellService', 'Marketing')
        ->set('upsellAmount', '800')
        ->set('upsellPaid', '200')
        ->call('recordUpsell')
        ->assertHasNoErrors()
        ->assertSee('£600 to collect');

    expect((float) $client->fresh()->lifetime_value)->toBe(2300.0);

    Livewire::actingAs($this->admin)
        ->test(ClientsIndex::class, ['brand' => $this->brand])
        ->assertSee('Still to collect')
        ->assertSee('£600 to collect');

    expect(SalesReport::forBrand($this->brand, $this->admin, now()->format('Y-m'))->totals()['due'])->toBe(600.0);
});

it('counts a sale from before part payments as paid in full', function (): void {
    $sale = Sale::query()->create([
        'brand_id' => $this->brand->getKey(), 'lead_id' => $this->lead->getKey(), 'client_id' => Client::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Old Client'])->getKey(),
        'user_id' => $this->seller->getKey(), 'amount' => 900, 'currency' => 'GBP', 'closed_at' => now(),
    ]);

    expect($sale->paid())->toBe(900.0)
        ->and($sale->due())->toBe(0.0)
        ->and($this->admin->can('recordPayment', $sale))->toBeFalse();
});
