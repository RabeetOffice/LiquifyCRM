<?php

declare(strict_types=1);

use App\Modules\Core\Livewire\AdminDashboard;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\Target;
use App\Modules\System\Support\Changelog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * The two dashboards: admin overview and brand dashboard with the setup
 * checklist, the New / Unassigned queue, cards and charts, by role.
 */

it('shows the admin overview with cards and the add brand button', function (): void {
    brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);

    $this->actingAs(superAdmin())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Every brand at a glance')
        ->assertSee('Active brands')
        ->assertSee('Manage brands')
        ->assertSee('Add brand')
        ->assertSee('Sales this month')
        ->assertSee('Unassigned leads')
        ->assertDontSee('Managers and Agents')
        ->assertDontSee('Teams across all brands')
        // The version is the last thing on the page.
        ->assertSeeInOrder(['Brands this month', 'Leads per brand', 'Recent activity', 'LiquifyCRM '.Changelog::latestVersion(), 'An update is ready to apply']);
});

it('puts every brand side by side in its own currency and leaves removed brands out', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));

    $uk = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $us = brand(['name' => 'US Books', 'slug' => 'usb', 'currency' => 'USD']);
    $gone = brand(['name' => 'Closed Brand', 'slug' => 'closed']);
    $agent = agentOf(department($uk));

    // UK: three leads this month (one owned), two on the same days last month and one later in that month.
    Lead::factory()->count(2)->create(['brand_id' => $uk->getKey(), 'created_at' => '2026-10-03 09:00:00']);
    $owned = Lead::factory()->create(['brand_id' => $uk->getKey(), 'assigned_to' => $agent->getKey(), 'created_at' => '2026-10-10 09:00:00']);
    Lead::factory()->count(2)->create(['brand_id' => $uk->getKey(), 'created_at' => '2026-09-08 09:00:00']);
    Lead::factory()->create(['brand_id' => $uk->getKey(), 'created_at' => '2026-09-25 09:00:00']);
    Lead::factory()->create(['brand_id' => $us->getKey(), 'assigned_to' => $agent->getKey(), 'created_at' => '2026-10-14 09:00:00']);
    Lead::factory()->count(5)->create(['brand_id' => $gone->getKey(), 'created_at' => '2026-10-12 09:00:00']);
    $gone->delete();

    Task::query()->create(['brand_id' => $uk->getKey(), 'lead_id' => $owned->getKey(), 'user_id' => $agent->getKey(), 'title' => 'Call back', 'due_at' => '2026-10-13 10:00:00']);

    $client = Client::query()->create(['brand_id' => $uk->getKey(), 'name' => 'Adam Young', 'sales_count' => 1]);
    Sale::query()->create(['brand_id' => $uk->getKey(), 'client_id' => $client->getKey(), 'amount' => 1500, 'currency' => 'GBP', 'closed_at' => '2026-10-11 15:00:00']);
    Target::query()->create(['brand_id' => $uk->getKey(), 'targetable_type' => Target::BRAND, 'targetable_id' => $uk->getKey(), 'period' => '2026-10', 'revenue_target' => 3000]);

    $page = Livewire::actingAs(admin())->test(AdminDashboard::class)
        ->assertViewHas('leadsThisMonth', 4)
        ->assertViewHas('sales', 1)
        ->assertViewHas('unassigned', 5)
        ->assertViewHas('overdue', 1)
        ->assertSee('£1,500')
        ->assertSee('$0')
        ->assertDontSee('Closed Brand');

    $rows = collect($page->viewData('rows'))->keyBy(fn (array $row): string => $row['brand']->slug);

    expect($rows->keys()->all())->toBe(['ukph', 'usb'])
        ->and($rows['ukph']['leads'])->toBe(3)
        ->and($rows['ukph']['previous'])->toBe(2)
        ->and($rows['ukph']['delta'])->toBe(['text' => '+50%', 'up' => true])
        ->and($rows['ukph']['unassigned'])->toBe(5)
        ->and($rows['ukph']['overdue'])->toBe(1)
        ->and($rows['ukph']['progress']->percent())->toBe(50)
        ->and($rows['usb']['leads'])->toBe(1)
        ->and($rows['usb']['progress']->currency)->toBe('USD');
});

it('keeps Managers and Agents out of the admin area', function (): void {
    $brand = brand(['slug' => 'ukph']);
    $department = department($brand);

    $this->actingAs(managerOf($department))->get('/admin')->assertForbidden();
    $this->flushSession();
    $this->actingAs(agentOf($department))->get('/admin/brands')->assertForbidden();
});

it('shows the brand dashboard with the setup checklist and the queue to a global admin', function (): void {
    $brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $department = department($brand, ['name' => 'Sales Team A']);
    managerOf($department);
    Lead::factory()->create(['brand_id' => $brand->getKey(), 'name' => 'Sarah Lee']);

    $this->actingAs(superAdmin())
        ->get('/b/ukph')
        ->assertOk()
        ->assertSee('Here is the summary for UK Publishing House.')
        ->assertSee('New / Unassigned')
        ->assertSee('Sarah Lee')
        ->assertSee('New leads today')
        ->assertSee('Leads per day');
});

it('gives an Agent their own numbers and follow-ups instead of the queue', function (): void {
    $brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $department = department($brand, ['name' => 'Sales Team A']);
    $agent = agentOf($department);
    Lead::factory()->create(['brand_id' => $brand->getKey(), 'name' => 'Queued Lead']);

    $this->actingAs($agent)
        ->get('/b/ukph')
        ->assertOk()
        ->assertSee('My open leads')
        ->assertSee('Follow-ups due today')
        ->assertDontSee('Set up UK Publishing House')
        ->assertDontSee('Queued Lead');
});
