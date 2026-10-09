<?php

declare(strict_types=1);

use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\TargetsPage;
use App\Modules\Sales\Livewire\SalesDashboard;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\Target;
use App\Modules\Sales\Support\Progress;
use App\Modules\Sales\Support\SalesReport;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Week 5: Mark as Won, clients with lifetime value, monthly targets at three
 * levels and the sales dashboard (one brand at a time, in its own currency).
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department, ['name' => 'Aisha Agent']);
    $this->otherAgent = agentOf($this->department, ['name' => 'Omar Other']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create([
        'brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(),
        'name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'phone' => '+447700900123', 'fields' => ['service' => 'Publishing'],
    ]);

    $this->period = now()->format('Y-m');
    $this->setTarget = fn (string $type, int $id, ?float $revenue, ?int $count = null, ?string $period = null) => Target::query()->create([
        'brand_id' => $this->brand->getKey(), 'targetable_type' => $type, 'targetable_id' => $id,
        'period' => $period ?? $this->period, 'revenue_target' => $revenue, 'sales_count_target' => $count,
    ]);
});

it('records a sale from Mark as Won, creates the client and moves the lead to Won', function (): void {
    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertSee('Mark as Won')
        ->call('openWon')
        ->assertSet('wonService', 'Publishing')
        ->set('wonAmount', '')
        ->call('recordSale')
        ->assertHasErrors('wonAmount')
        ->set('wonAmount', '1500')
        ->call('recordSale')
        ->assertHasNoErrors()
        ->assertSee('Add another sale');

    $sale = Sale::query()->sole();
    $client = Client::query()->sole();
    $lead = $this->lead->refresh();

    expect((float) $sale->amount)->toBe(1500.0)
        ->and($sale->currency)->toBe('GBP')
        ->and((float) $sale->amount_reporting)->toBe(1500.0)
        ->and($sale->user_id)->toBe($this->agent->getKey())
        ->and($sale->department_id)->toBe($this->department->getKey())
        ->and($sale->service)->toBe('Publishing')
        ->and($client->name)->toBe('Sarah Lee')
        ->and((float) $client->lifetime_value)->toBe(1500.0)
        ->and($client->sales_count)->toBe(1)
        ->and($lead->client_id)->toBe($client->getKey())
        ->and($lead->status->is_won)->toBeTrue()
        ->and((float) $lead->value)->toBe(1500.0)
        ->and($lead->activities()->where('event', 'sales.recorded')->count())->toBe(1);
});

it('attaches a later purchase by the same email to the same client', function (): void {
    app(RecordSale::class)->handle($this->lead, 1500, 'Publishing', now(), $this->agent);

    // A new enquiry from a buyer starts a new lead that already knows its client.
    $second = app(CreateLead::class)->handle($this->brand, ['name' => 'S. Lee', 'email' => 'SARAH@example.com', 'assigned_to' => $this->otherAgent->getKey(), 'department_id' => $this->department->getKey()])->lead;
    $client = Client::query()->sole();

    expect($second->getKey())->not->toBe($this->lead->getKey())
        ->and($second->client_id)->toBe($client->getKey());

    app(RecordSale::class)->handle($second, 900.50, 'Marketing', now(), $this->otherAgent);

    expect(Client::query()->count())->toBe(1)
        ->and((float) $client->refresh()->lifetime_value)->toBe(2400.50)
        ->and($client->sales_count)->toBe(2);
});

it('lets only the right people record a sale and never sets Won without an amount', function (): void {
    Livewire::actingAs($this->otherAgent)->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])->assertForbidden();

    $won = LeadStatus::wonFor($this->brand);

    // Choosing Won in "Change status" opens the sale sheet instead.
    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openStatus')
        ->set('statusTo', (string) $won->getKey())
        ->call('changeStatus')
        ->assertSet('showStatus', false)
        ->assertSet('showWon', true);

    // Dragging to Won on the pipeline is refused.
    Livewire::actingAs($this->agent)->test(Pipeline::class, ['brand' => $this->brand])->call('move', $this->lead->getKey(), $won->getKey());

    expect($this->lead->refresh()->status->is_won)->toBeFalse()
        ->and(Sale::query()->count())->toBe(0);

    $this->actingAs($this->otherAgent)->postJson('/b/ukph/leads/'.$this->lead->getKey().'/won', ['amount' => 100])->assertForbidden();
    $this->flushSession();
    $this->actingAs($this->manager)->postJson('/b/ukph/leads/'.$this->lead->getKey().'/won', ['amount' => 100, 'service' => 'Editing'])->assertCreated();

    expect(Sale::query()->sole()->user_id)->toBe($this->agent->getKey());
});

it('counts one sale towards the Agent, the department and the brand at once', function (): void {
    ($this->setTarget)(Target::USER, $this->agent->getKey(), 1000);
    ($this->setTarget)(Target::DEPARTMENT, $this->department->getKey(), 6000);
    ($this->setTarget)(Target::BRAND, $this->brand->getKey(), null, 4);

    app(RecordSale::class)->handle($this->lead, 1500, 'Publishing', now(), $this->agent);

    $report = SalesReport::forBrand($this->brand, $this->admin, $this->period);
    $agentRow = collect($report->agentRows())->firstWhere(fn (array $row): bool => $row['user']->is($this->agent));

    expect($agentRow['progress']->percent())->toBe(150)
        ->and($agentRow['progress']->isOver())->toBeTrue()
        ->and($agentRow['progress']->overLabel())->toBe('150%, £500 over target')
        ->and($report->departmentRows()[0]['progress']->percent())->toBe(25)
        ->and($report->progress()->percent())->toBe(25)
        ->and($report->progress()->label())->toBe('1 of 4 sales')
        ->and(SalesReport::forBrand($this->brand, $this->agent, $this->period)->progress()->percent())->toBe(150)
        ->and(SalesReport::forBrand($this->brand, $this->otherAgent, $this->period)->totals()['count'])->toBe(0);
});

it('describes progress and over-achievement in plain words', function (): void {
    expect((new Progress(3200, 4, 5000, null, 'GBP'))->label())->toBe('£3,200 of £5,000')
        ->and((new Progress(3200, 4, 5000, null, 'GBP'))->percent())->toBe(64)
        ->and((new Progress(13200, 9, 10000, null, 'AUD'))->overLabel())->toBe('132%, A$3,200 over target')
        ->and((new Progress(0, 6, null, 4, 'GBP'))->overLabel())->toBe('150%, 2 sales over target')
        ->and((new Progress(100, 1, null, null, 'GBP'))->hasTarget())->toBeFalse();
});

it('shows the sales dashboard by role', function (): void {
    ($this->setTarget)(Target::USER, $this->agent->getKey(), 1000);
    app(RecordSale::class)->handle($this->lead, 1500, 'Publishing', now(), $this->agent);

    // The admin area: one brand at a time, picked from the brand cards, with the trend chart.
    $this->actingAs($this->admin)->get('/admin/sales')
        ->assertOk()
        ->assertSee('One brand at a time')
        ->assertSee('UK Publishing House')
        ->assertSee('£1,500')
        ->assertSee('Average sale')
        ->assertSee('The last six months')
        ->assertSee('Leaderboard');

    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/ukph/sales')->assertOk()->assertSee('My target')->assertSee('150%, £500 over target')->assertDontSee('Leaderboard');

    $this->flushSession();
    $this->actingAs($this->otherAgent)->get('/b/ukph/sales')->assertOk()->assertDontSee('£1,500');

    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/ukph/sales')->assertOk()->assertSee('Leaderboard')->assertSee('Aisha Agent');
    $this->actingAs($this->manager)->get('/admin/sales')->assertForbidden();

    Livewire::actingAs($this->admin)
        ->test(SalesDashboard::class)
        ->call('drill', (string) $this->brand->getKey(), (string) $this->department->getKey())
        ->assertSee('People in Sales Team A')
        ->assertSee('Omar Other');
});

it('shows each brand in its own currency in the admin area, never a converted total', function (): void {
    $anz = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz', 'currency' => 'AUD']);
    inBrand($anz);
    $lead = Lead::factory()->create(['brand_id' => $anz->getKey(), 'name' => 'Tom Hall', 'email' => 'tom@example.com']);
    app(RecordSale::class)->handle($lead, 2000, 'Printing', now(), $this->admin);

    inBrand($this->brand);
    app(RecordSale::class)->handle($this->lead, 1500, 'Publishing', now(), $this->agent);

    $report = SalesReport::forAdmin($this->period, (int) $anz->getKey());

    expect($report->currency())->toBe('AUD')
        ->and($report->totals()['revenue'])->toBe(2000.0)
        ->and(SalesReport::forAdmin($this->period, (int) $this->brand->getKey())->totals()['revenue'])->toBe(1500.0)
        ->and($report->trend()['labels'])->toHaveCount(6)
        ->and(array_sum($report->trend()['revenue']))->toBe(2000.0)
        ->and(last($report->trend()['count']))->toBe(1);

    inBrand(null);

    // Both brand cards show their own month; the chosen one fills the page.
    Livewire::actingAs($this->admin)
        ->test(SalesDashboard::class)
        ->assertSee('A$2,000')
        ->assertSee('£1,500')
        ->call('drill', (string) $this->brand->getKey())
        ->assertSee('Sales Team A')
        ->assertDontSee('Tom Hall')
        ->call('drill', (string) $anz->getKey())
        ->assertSee('Tom Hall');

    $this->actingAs($this->admin)->get('/admin/exchange-rates')->assertNotFound();
});

it('sets targets in the month grid: Admin every row, a Manager only their Agents', function (): void {
    $brandKey = 'brand:'.$this->brand->getKey();
    $departmentKey = 'department:'.$this->department->getKey();
    $agentKey = 'user:'.$this->agent->getKey();
    $managerKey = 'user:'.$this->manager->getKey();

    Livewire::actingAs($this->admin)
        ->test(TargetsPage::class, ['brand' => $this->brand])
        ->assertSee('Sales Team A')
        ->assertSee('Aisha Agent')
        ->set("values.{$brandKey}.revenue", '20000')
        ->set("values.{$departmentKey}.revenue", '8000')
        ->set("values.{$departmentKey}.count", '6')
        ->set("values.{$managerKey}.revenue", '3000')
        ->call('save')
        ->assertHasNoErrors();

    expect(Target::query()->count())->toBe(3)
        ->and((float) Target::query()->where('targetable_type', 'department')->sole()->revenue_target)->toBe(8000.0)
        ->and(Target::query()->where('targetable_type', 'department')->sole()->sales_count_target)->toBe(6);

    // The Manager changes an Agent, and cannot touch their own or the department's target.
    Livewire::actingAs($this->manager)
        ->test(TargetsPage::class, ['brand' => $this->brand])
        ->assertDontSee('Whole brand')
        ->set("values.{$agentKey}.revenue", '2500')
        ->set("values.{$managerKey}.revenue", '1')
        ->set("values.{$departmentKey}.revenue", '1')
        ->call('save');

    expect((float) Target::query()->where('targetable_type', 'user')->where('targetable_id', $this->agent->getKey())->sole()->revenue_target)->toBe(2500.0)
        ->and((float) Target::query()->where('targetable_type', 'user')->where('targetable_id', $this->manager->getKey())->sole()->revenue_target)->toBe(3000.0)
        ->and((float) Target::query()->where('targetable_type', 'department')->sole()->revenue_target)->toBe(8000.0);

    $this->actingAs($this->agent)->get('/b/ukph/targets')->assertForbidden();
});

it('copies last month into the empty rows of this month', function (): void {
    $last = now()->subMonth()->format('Y-m');
    ($this->setTarget)(Target::BRAND, $this->brand->getKey(), 20000, null, $last);
    ($this->setTarget)(Target::USER, $this->agent->getKey(), 2000, 3, $last);
    ($this->setTarget)(Target::USER, $this->agent->getKey(), 2600);

    Livewire::actingAs($this->admin)
        ->test(TargetsPage::class, ['brand' => $this->brand])
        ->call('copyLastMonth');

    $now = Target::query()->where('period', $this->period)->get();

    expect($now)->toHaveCount(2)
        ->and((float) $now->firstWhere('targetable_type', 'brand')->revenue_target)->toBe(20000.0)
        ->and((float) $now->firstWhere('targetable_type', 'user')->revenue_target)->toBe(2600.0);
});

it('lists clients for Admins and for the Manager whose department sold to them', function (): void {
    app(RecordSale::class)->handle($this->lead, 1500, 'Publishing', now(), $this->agent);
    $client = Client::query()->sole();

    $elsewhere = department($this->brand, ['name' => 'Consultants']);
    $otherManager = managerOf($elsewhere);
    inBrand($this->brand);

    $this->actingAs($this->manager)->get('/b/ukph/clients')->assertOk()->assertSee('Sarah Lee')->assertSee('£1,500');
    $this->actingAs($this->manager)->get('/b/ukph/clients/'.$client->getKey())->assertOk()->assertSee('Aisha Agent')->assertSee('Publishing');

    $this->flushSession();
    $this->actingAs($otherManager)->get('/b/ukph/clients')->assertOk()->assertDontSee('Sarah Lee');
    $this->actingAs($otherManager)->get('/b/ukph/clients/'.$client->getKey())->assertForbidden();

    $this->flushSession();
    $this->actingAs($this->agent)->get('/b/ukph/clients')->assertForbidden();
});

it('shows each person their own target bar on Profile, and none to a global admin', function (): void {
    ($this->setTarget)(Target::USER, $this->agent->getKey(), 5000);
    app(RecordSale::class)->handle($this->lead, 1500, 'Publishing', now(), $this->agent);

    $this->actingAs($this->agent)->get('/profile')
        ->assertOk()
        ->assertSee('Your target this month')
        ->assertSee('UK Publishing House')
        ->assertSee('£1,500 of £5,000')
        ->assertSee('30%');

    $this->flushSession();
    $this->actingAs($this->otherAgent)->get('/profile')->assertOk()->assertSee('No target set for this month');

    $this->flushSession();
    $this->actingAs($this->admin)->get('/profile')->assertOk()->assertDontSee('Your target this month');
});
