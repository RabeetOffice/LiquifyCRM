<?php

declare(strict_types=1);

use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\CorrectSale;
use App\Modules\Sales\Actions\CreateInvoice;
use App\Modules\Sales\Actions\RecordPayment;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\Target;
use App\Modules\Sales\Support\ExchangeRates;
use App\Modules\Sales\Support\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * A sale in another currency (user request, 20 Oct 2026): a client of the
 * euro brand pays in pounds. The sale counts in euros at that day's rate, so
 * the targets add up; what the client was charged is kept and shown.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));

    // The rate service: GBP to EUR today and on 14 October; anything else, or everything while it is "down", fails.
    $this->down = false;
    Http::fake(fn (Request $request) => match (true) {
        $this->down => Http::response([], 500),
        str_contains($request->url(), '/v1/latest') && str_contains($request->url(), 'base=GBP') => Http::response(['amount' => 1.0, 'base' => 'GBP', 'date' => '2026-10-19', 'rates' => ['EUR' => 1.1876]]),
        str_contains($request->url(), '/v1/2026-10-14') && str_contains($request->url(), 'base=GBP') => Http::response(['amount' => 1.0, 'base' => 'GBP', 'date' => '2026-10-14', 'rates' => ['EUR' => 1.15]]),
        default => Http::response([], 500),
    });

    $this->brand = brand(['name' => 'Ireland Publishing House', 'slug' => 'ie', 'currency' => 'EUR']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->seller = agentOf($this->front, ['name' => 'Arlo Morris']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->seller->getKey(),
        'name' => 'Sarah Lee', 'email' => 'sarah@example.com',
    ]);
});

it('records a pound sale for a euro brand at the day\'s rate and counts it in euros towards the target', function (): void {
    Target::query()->create(['brand_id' => $this->brand->getKey(), 'targetable_type' => 'user', 'targetable_id' => $this->seller->getKey(), 'period' => '2026-10', 'revenue_target' => 5000]);

    Livewire::actingAs($this->seller)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openWon')
        ->assertSet('wonCurrency', 'EUR')
        ->set('wonCurrency', 'GBP')
        ->assertSet('dealRate', 1.1876)
        ->assertSee('at today\'s rate of 1', false)
        ->set('wonAmount', '1000')
        ->set('wonService', 'Publishing')
        ->call('recordSale')
        ->assertHasNoErrors()
        ->assertSee('£1,000 (€1,187.60)');

    $sale = Sale::query()->sole();

    expect((float) $sale->amount)->toBe(1187.6)
        ->and($sale->currency)->toBe('EUR')
        ->and($sale->charged_currency)->toBe('GBP')
        ->and((float) $sale->charged_amount)->toBe(1000.0)
        ->and((float) $sale->exchange_rate)->toBe(1.1876)
        ->and($sale->isPaidInFull())->toBeTrue()
        ->and($sale->label())->toBe('£1,000 (€1,187.60)');

    // The target reads the brand's currency.
    expect(SalesReport::forBrand($this->brand, $this->seller, '2026-10')->progress()->revenue)->toBe(1187.6);

    $this->assertDatabaseHas('activity_log', ['event' => 'sales.recorded', 'description' => 'Sale recorded: £1,000 (€1,187.60) · Publishing']);
});

it('takes part payments in the currency the client pays in, and settles the deal exactly', function (): void {
    $sale = app(RecordSale::class)->handle($this->lead, 1000, 'Publishing', now(), $this->seller, 400, 'GBP');

    expect((float) $sale->amount_paid)->toBe(475.04)
        ->and((float) $sale->charged_paid)->toBe(400.0)
        ->and($sale->dealDue())->toBe(600.0);

    $payments = app(RecordPayment::class);
    expect(fn () => $payments->handle($sale, 650, now(), $this->seller))->toThrow(InvalidArgumentException::class, 'That is more than the £600 still to collect.');

    $payments->handle($sale, 600, now(), $this->seller);
    $sale->refresh();

    // The last payment takes exactly what was left in euros: no cent too many or too few.
    expect($sale->isPaidInFull())->toBeTrue()
        ->and((float) $sale->amount_paid)->toBe(1187.6)
        ->and((float) $sale->charged_paid)->toBe(1000.0)
        ->and($sale->payments->pluck('charged_amount')->map(fn ($v): float => (float) $v)->all())->toBe([400.0, 600.0]);
});

it('uses the rate of the close date, and the last known rate when the service cannot be reached', function (): void {
    $sale = app(RecordSale::class)->handle($this->lead, 1000, null, CarbonImmutable::parse('2026-10-14 10:00:00'), $this->seller, null, 'GBP');
    expect((float) $sale->amount)->toBe(1150.0)->and((float) $sale->exchange_rate)->toBe(1.15);

    // The service is down: a rate seen before is used, and a pair never seen cannot be recorded.
    $this->down = true;
    Cache::forget('fx:GBP:EUR:latest');

    expect(app(ExchangeRates::class)->rate('GBP', 'EUR'))->toBe(1.15)
        ->and(fn () => app(RecordSale::class)->handle($this->lead, 500, null, now(), $this->seller, null, 'USD'))
        ->toThrow(InvalidArgumentException::class, 'The USD to EUR rate could not be fetched just now.');
});

it('changes nothing for a sale in the brand\'s own currency and sends nothing to the rate service', function (): void {
    $sale = app(RecordSale::class)->handle($this->lead, 900, null, now(), $this->seller, null, 'EUR');

    expect($sale->isForeign())->toBeFalse()
        ->and($sale->charged_currency)->toBeNull()
        ->and($sale->label())->toBe('€900');

    Http::assertNothingSent();
});

it('records an upsell in another currency, corrects it at its own rate, and invoices it as charged', function (): void {
    $sale = app(RecordSale::class)->handle($this->lead, 1000, 'Publishing', now(), $this->seller);
    $client = Client::query()->findOrFail($sale->client_id);

    Livewire::actingAs($this->admin)
        ->test(ClientShow::class, ['brand' => $this->brand, 'client' => $client])
        ->call('openUpsell')
        ->set('upsellCurrency', 'GBP')
        ->set('upsellAmount', '200')
        ->set('upsellService', 'Marketing')
        ->call('recordUpsell')
        ->assertHasNoErrors();

    $upsell = Sale::query()->where('kind', 'upsell')->sole();
    expect((float) $upsell->amount)->toBe(237.52)->and($upsell->charged_currency)->toBe('GBP');

    app(CorrectSale::class)->update($upsell, 300, 'Marketing', $upsell->closed_at, $this->admin);
    $upsell->refresh();
    expect((float) $upsell->charged_amount)->toBe(300.0)->and((float) $upsell->amount)->toBe(356.28);

    $invoice = app(CreateInvoice::class)->handle($upsell, ['name' => 'Sarah Lee', 'email' => null, 'phone' => null, 'address' => null], 'Marketing', now(), null, null, $this->admin);
    expect($invoice->currency)->toBe('GBP')->and((float) $invoice->total)->toBe(300.0);

    // Only currency codes and a date ever went to the rate service.
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), ExchangeRates::SOURCE) && ! str_contains($request->url(), 'Sarah'));
});
