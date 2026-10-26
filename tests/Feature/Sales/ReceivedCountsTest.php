<?php

declare(strict_types=1);

use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\RecordPayment;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\TargetsPage;
use App\Modules\Sales\Models\Target;
use App\Modules\Sales\Support\SalesReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * What counts as achieved (user decision, 26 Oct 2026). An Agent sold A$429
 * and the client paid A$195: the dashboard and the target counted all A$429.
 * Now only money received counts; the A$234 waits under "Still to collect"
 * and counts in the month it comes in.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00'));

    $this->brand = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz', 'currency' => 'AUD']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->front, ['name' => 'Farah Front']);
    $this->seller = agentOf($this->front, ['name' => 'Sammy Arland']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->front->getKey(), 'assigned_to' => $this->seller->getKey(),
        'name' => 'Susan Collins', 'email' => 'susan@example.com',
    ]);

    Target::query()->create([
        'brand_id' => $this->brand->getKey(), 'targetable_type' => Target::USER, 'targetable_id' => $this->seller->getKey(),
        'period' => '2026-10', 'revenue_target' => 1000,
    ]);

    $this->sale = app(RecordSale::class)->handle($this->lead, 429, 'Publishing', now(), $this->seller, 195);
});

it('counts only what the client paid towards the seller\'s target, and shows the rest as still to collect', function (): void {
    $report = SalesReport::forBrand($this->brand, $this->seller, '2026-10');
    $totals = $report->totals();

    expect($totals['revenue'])->toBe(195.0)
        ->and($totals['sold'])->toBe(429.0)
        ->and($totals['count'])->toBe(1)
        ->and($totals['due'])->toBe(234.0)
        ->and($totals['owing'])->toBe(1)
        ->and($report->progress()->revenue)->toBe(195.0)
        ->and($report->progress()->label())->toBe('A$195 of A$1,000')
        ->and($report->progress()->summary())->toBe('A$805 to go · 1 sale so far')
        ->and(SalesReport::forPerson($this->brand, $this->seller, '2026-10')->progress()->revenue)->toBe(195.0);

    $this->actingAs($this->seller)->get('/b/anz/sales')
        ->assertOk()
        ->assertSee('A$195 of A$1,000')
        ->assertSee('Money received')
        ->assertSee('A$429 in deals')
        ->assertSee('Still to collect')
        ->assertSee('A$234')
        ->assertSee('A$195 paid · A$234 to collect');

    // The Manager's leaderboard and the Targets screen count the same money.
    expect(collect(SalesReport::forBrand($this->brand, $this->manager, '2026-10')->agentRows())->firstWhere('user.id', $this->seller->getKey())['progress']->revenue)->toBe(195.0);

    Livewire::actingAs($this->admin)
        ->test(TargetsPage::class, ['brand' => $this->brand])
        ->assertSee('A$195 of A$1,000');
});

it('counts the rest in the month it comes in, and leaves the month of the sale as it was', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00'));
    app(RecordPayment::class)->handle($this->sale->fresh(), 234, now(), $this->seller);

    $october = SalesReport::forBrand($this->brand, $this->seller, '2026-10')->totals();
    $november = SalesReport::forBrand($this->brand, $this->seller, '2026-11')->totals();

    expect($october['revenue'])->toBe(195.0)
        ->and($october['count'])->toBe(1)
        ->and($november['revenue'])->toBe(234.0)
        ->and($november['count'])->toBe(0)
        ->and($november['due'])->toBe(0.0)
        ->and(SalesReport::forBrand($this->brand, $this->admin, '2026-11')->trend(2)['revenue'])->toBe([195.0, 234.0]);
});
