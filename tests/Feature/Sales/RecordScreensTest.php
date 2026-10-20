<?php

declare(strict_types=1);

use App\Modules\Activity\Livewire\LogsPage;
use App\Modules\Core\Support\Audit;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\RecordSale;
use App\Modules\Sales\Livewire\App\ClientsIndex;
use App\Modules\Sales\Livewire\App\TargetsPage;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Target;
use App\Modules\Sites\Enums\CheckStatus;
use App\Modules\Sites\Models\Site;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The redesigned record screens (12 October): clients with their totals and
 * orders, the client page, targets in blocks per department, websites with
 * their checks, and logs as a feed with filter chips.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department, ['name' => 'Aisha Agent']);
    $this->admin = admin();

    inBrand($this->brand);

    $this->sell = function (string $name, float $amount, int $times = 1): Client {
        $lead = Lead::factory()->create([
            'brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(),
            'name' => $name, 'email' => str($name)->slug('.').'@example.com',
        ]);

        foreach (range(1, $times) as $ignored) {
            app(RecordSale::class)->handle($lead->fresh(), $amount, 'Publishing', now(), $this->agent);
        }

        return Client::query()->where('name', $name)->sole();
    };
});

it('sums up the clients and offers the list in four orders', function (): void {
    ($this->sell)('Sarah Lee', 1500);
    ($this->sell)('Adam Young', 400, 2);

    Livewire::actingAs($this->admin)
        ->test(ClientsIndex::class, ['brand' => $this->brand])
        ->assertViewHas('summary', fn (array $summary): bool => $summary['clients'] === 2
            && $summary['value'] === 2300.0
            && $summary['average'] === 1150.0
            && $summary['returning'] === 1
            && $summary['top'] === 1500.0)
        ->assertViewHas('order', 'value')
        ->assertSee('£2,300')
        ->assertSee('1 of 2')
        ->assertSeeInOrder(['Sarah Lee', 'Adam Young'])
        ->call('order', 'sales')
        ->assertViewHas('order', 'sales')
        ->assertSeeInOrder(['Adam Young', 'Sarah Lee'])
        ->call('order', 'name')
        ->assertSet('sort', 'name')
        ->assertSet('direction', 'asc')
        ->call('order', 'anything-else')
        ->assertSet('sort', 'name')
        ->set('search', 'sarah')
        ->assertSee('Sarah Lee')
        ->assertDontSee('Adam Young');
});

it('shows a client with what they are worth, who closed each sale and their enquiries', function (): void {
    $client = ($this->sell)('Sarah Lee', 600, 2);

    $this->actingAs($this->admin)
        ->get('/b/ukph/clients/'.$client->getKey())
        ->assertOk()
        ->assertSee('Lifetime value')
        ->assertSee('£1,200')
        ->assertSee('Average sale')
        ->assertSee('£600')
        ->assertSee('bought 2 times')
        ->assertSee('Aisha Agent')
        ->assertSee('Enquiries')
        ->assertSee('Correct');

    $this->flushSession();
    // A front Manager sees what their own team sold, not the client's whole value.
    $this->actingAs($this->manager)->get('/b/ukph/clients/'.$client->getKey())->assertOk()->assertSee('Sold by your team')->assertDontSee('Lifetime value')->assertDontSee('Correct');
});

it('lays the targets out in a block per department with what is left of the month', function (): void {
    ($this->sell)('Sarah Lee', 1500);
    Target::query()->create(['brand_id' => $this->brand->getKey(), 'targetable_type' => Target::USER, 'targetable_id' => $this->agent->getKey(), 'period' => now()->format('Y-m'), 'revenue_target' => 1000]);

    Livewire::actingAs($this->admin)
        ->test(TargetsPage::class, ['brand' => $this->brand])
        ->assertSee('Revenue this month')
        ->assertSee('£1,500')
        ->assertSee('People with a target')
        ->assertSee('1 of 2')
        ->assertSeeInOrder(['Whole brand', 'Sales Team A', 'Aisha Agent'])
        ->assertSee('150%, £500 over target')
        ->assertSee('Targets changed, not saved yet')
        // The columns say what they are, and a team without its own target shows what its people's add up to.
        ->assertSeeInOrder(['Value of sales (GBP)', 'Number of sales', now()->format('F').' so far'])
        ->assertSee('Its people&#039;s targets add up to £1,000', false)
        ->assertSee('No target for this month');

    // A Manager sets only their own Agents: the department's own line is read-only and there is no brand line.
    Livewire::actingAs($this->manager)
        ->test(TargetsPage::class, ['brand' => $this->brand])
        ->assertDontSee('Whole brand')
        ->assertDontSee('Revenue this month')
        ->assertSee('Its people&#039;s targets add up to £1,000', false)
        ->assertSee('Aisha Agent');
});

it('shows each website with its state and counts the healthy ones', function (): void {
    Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'UKPH main site', 'health_status' => CheckStatus::Ok]);
    $shaky = Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'UKPH landing page', 'health_status' => CheckStatus::Warn]);

    $this->actingAs($this->admin)
        ->get('/b/ukph/sites')
        ->assertOk()
        ->assertSee('1 of 2')
        ->assertSee('Need a look')
        ->assertSee('UKPH main site')
        ->assertSee('UKPH landing page');

    $this->get('/b/ukph/sites/'.$shaky->getKey())
        ->assertOk()
        ->assertSee('Connection')
        ->assertSee('Waiting for the first lead')
        ->assertSee('Last check')
        ->assertSee('Not checked yet.');
});

it('shows the logs as a feed by day with the filters in use as chips', function (): void {
    $entry = function (string $description, int $daysAgo, string $area, ?object $causer = null): void {
        $activity = Audit::log($area.'.tested', null, [], $description, brand: $this->brand, causer: $causer ?? $this->admin, logName: $area);
        $activity->forceFill(['created_at' => now()->subDays($daysAgo)->setTime(10, 0)])->save();
    };

    // Oldest first, as they would have been written.
    $entry('Target set for October', 3, 'sales');
    $entry('Lead assigned to Aisha', 1, 'leads', $this->manager);
    $entry('Invited Omar to the brand', 0, 'members');

    Livewire::actingAs($this->admin)
        ->test(LogsPage::class)
        ->assertSeeInOrder(['Today', 'Invited Omar to the brand', 'Yesterday', 'Lead assigned to Aisha', 'Target set for October'])
        ->set('area', 'leads')
        ->assertSee('Lead assigned to Aisha')
        ->assertDontSee('Invited Omar to the brand')
        ->set('area', '')
        ->set('person', (string) $this->manager->getKey())
        ->assertSee('By: '.$this->manager->name)
        ->assertDontSee('Invited Omar to the brand')
        ->set('from', now()->subDays(2)->toDateString())
        ->assertViewHas('chips', fn (array $chips): bool => array_keys($chips) === ['person', 'from'])
        ->call('removeFilter', 'person')
        ->assertSet('person', '')
        ->assertSee('Invited Omar to the brand')
        ->assertDontSee('Target set for October')
        ->call('removeFilter', 'deleteBefore')
        ->assertSet('from', now()->subDays(2)->toDateString())
        ->call('clearFilters')
        ->assertSee('Target set for October');
});
