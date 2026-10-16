<?php

declare(strict_types=1);

use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\AdminDashboard;
use App\Modules\Core\Support\OverviewCards;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\Task;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Sales\Models\Client;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\Target;
use App\Modules\Sites\Models\Site;
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
        ->assertSeeInOrder(['Leads per day', 'Today', '7 days', '30 days', 'Brands this month', 'Recent activity', 'LiquifyCRM '.Changelog::latestVersion(), 'An update is ready to apply']);
});

it('shows leads per day of every brand for today, 7 or 30 days, in the time of whoever looks', function (): void {
    // 12:00 UTC is 23:00 in Sydney: there, today began at 13:00 UTC yesterday.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));

    $uk = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $au = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz']);

    Lead::factory()->create(['brand_id' => $au->getKey(), 'created_at' => '2026-10-14 13:30:00']);
    Lead::factory()->count(2)->create(['brand_id' => $uk->getKey(), 'created_at' => '2026-10-15 09:10:00']);
    Lead::factory()->create(['brand_id' => $uk->getKey(), 'created_at' => '2026-10-10 09:00:00']);
    Lead::factory()->create(['brand_id' => $uk->getKey(), 'created_at' => '2026-09-20 09:00:00']);
    Lead::factory()->create(['brand_id' => $uk->getKey(), 'created_at' => '2026-09-01 09:00:00']);

    $page = Livewire::actingAs(admin(['timezone' => 'Australia/Sydney']))->test(AdminDashboard::class);

    $week = $page->viewData('perDay');

    expect($week['total'])->toBe(4)
        ->and($week['labels'])->toBe(['Fri 9', 'Sat 10', 'Sun 11', 'Mon 12', 'Tue 13', 'Wed 14', 'Thu 15'])
        ->and(array_map(fn (array $row): string => $row['brand']->slug, $week['brands']))->toBe(['ukph', 'anz'])
        ->and($week['datasets'][0]['data'])->toBe([0, 1, 0, 0, 0, 0, 2])
        ->and($week['datasets'][1]['data'])->toBe([0, 0, 0, 0, 0, 0, 1])
        ->and($week['datasets'][0]['backgroundColor'])->not->toBe($week['datasets'][1]['backgroundColor']);

    $today = $page->set('range', 'today')->viewData('perDay');

    expect($today['total'])->toBe(3)
        ->and($today['hourly'])->toBeTrue()
        ->and($today['labels'])->toHaveCount(24)
        ->and($today['datasets'][0]['data'][20])->toBe(2)
        ->and($today['datasets'][1]['data'][0])->toBe(1);

    $month = $page->set('range', '30')->assertSet('range', '30')->viewData('perDay');

    expect($month['total'])->toBe(5)
        ->and($month['labels'])->toHaveCount(30)
        ->and($month['labels'][0])->toBe('16 Sep')
        ->and($month['brands'][0]['total'])->toBe(4);

    // Anything else in the address falls back to 7 days.
    expect($page->set('range', 'forever')->viewData('perDay')['days'])->toBe(7);
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

it('answers the questions of the cards under the brands table', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));

    $uk = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'currency' => 'GBP']);
    $au = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz', 'currency' => 'AUD']);
    $agent = agentOf(department($uk), ['name' => 'Sam Carter']);
    $make = fn (Brand $brand, string $source, string $at, ?string $contacted = null): Lead => Lead::factory()->create(['brand_id' => $brand->getKey(), 'source_type' => $source, 'created_at' => $at, 'first_contacted_at' => $contacted]);

    // UK: contacted after 10 and 90 minutes, one waiting for three hours, one imported (left out of the speed).
    $quick = $make($uk, 'website', '2026-10-14 09:00:00', '2026-10-14 09:10:00');
    $make($uk, 'website', '2026-10-14 10:00:00', '2026-10-14 11:30:00');
    $make($uk, 'facebook', '2026-10-15 09:00:00');
    $make($uk, 'import', '2026-10-13 09:00:00');
    // ANZ: a chat 20 minutes ago, not answered yet, so it still has time.
    $make($au, 'messenger', '2026-10-15 11:40:00');

    $client = Client::query()->create(['brand_id' => $uk->getKey(), 'name' => 'Adam Young', 'sales_count' => 2]);
    Sale::query()->create(['brand_id' => $uk->getKey(), 'client_id' => $client->getKey(), 'lead_id' => $quick->getKey(), 'user_id' => $agent->getKey(), 'kind' => 'front', 'amount' => 1000, 'currency' => 'GBP', 'closed_at' => '2026-10-14 15:00:00']);
    Sale::query()->create(['brand_id' => $uk->getKey(), 'client_id' => $client->getKey(), 'user_id' => $agent->getKey(), 'kind' => 'upsell', 'amount' => 500, 'currency' => 'GBP', 'closed_at' => '2026-10-15 10:00:00']);

    Conversation::query()->create(['brand_id' => $uk->getKey(), 'contact_external_id' => '1', 'last_inbound_at' => '2026-10-15 10:00:00', 'last_message_at' => '2026-10-15 10:00:00']);
    Conversation::query()->create(['brand_id' => $uk->getKey(), 'contact_external_id' => '2', 'last_inbound_at' => '2026-10-15 09:00:00', 'last_message_at' => '2026-10-15 09:30:00']);
    Conversation::query()->create(['brand_id' => $au->getKey(), 'contact_external_id' => '3', 'channel' => 'instagram', 'last_inbound_at' => '2026-10-14 05:00:00', 'last_message_at' => '2026-10-14 05:00:00']);

    Site::factory()->create(['brand_id' => $uk->getKey(), 'url' => 'https://quiet.example', 'last_lead_at' => '2026-10-12 09:00:00']);
    Site::factory()->create(['brand_id' => $uk->getKey(), 'url' => 'https://paused.example', 'last_lead_at' => '2026-10-01 09:00:00', 'is_active' => false]);
    Site::factory()->create(['brand_id' => $au->getKey(), 'url' => 'https://busy.example', 'last_lead_at' => '2026-10-15 11:00:00']);
    Site::factory()->create(['brand_id' => $au->getKey(), 'url' => 'https://broken.example', 'last_lead_at' => '2026-10-15 11:00:00', 'health_status' => 'fail']);

    Task::query()->create(['brand_id' => $uk->getKey(), 'lead_id' => $quick->getKey(), 'user_id' => $agent->getKey(), 'title' => 'Call back', 'due_at' => '2026-10-15 16:00:00']);
    Task::query()->create(['brand_id' => $uk->getKey(), 'lead_id' => $quick->getKey(), 'user_id' => $agent->getKey(), 'title' => 'Send the quote', 'due_at' => '2026-10-13 10:00:00']);

    $page = Livewire::actingAs(admin())->test(AdminDashboard::class)
        ->assertSee('First reply speed')
        ->assertSee('Top sellers this month')
        ->assertSee('Sam Carter')
        ->assertSee('quiet.example')
        ->assertDontSee('paused.example');

    $cards = $page->viewData('cards');

    // 1. The median of 10 and 90 minutes; one of the three leads that had their hour was answered within it.
    expect($cards['speed'][0]['brand']->slug)->toBe('ukph')
        ->and($cards['speed'][0]['median'])->toBe(50)
        ->and($cards['speed'][0]['leads'])->toBe(3)
        ->and($cards['speed'][0]['quick'])->toBe(33)
        ->and($cards['speed'][1]['median'])->toBeNull()
        ->and($cards['speed'][1]['quick'])->toBeNull();

    // 2. Sources, busiest brand first, in the order of the source list.
    expect($cards['sources']['brands'][0]['sources'])->toBe(['website' => 2, 'facebook' => 1, 'import' => 1])
        ->and(array_map(fn ($source) => $source->value, $cards['sources']['sources']))->toBe(['website', 'facebook', 'messenger', 'import']);

    // 3. One first sale (the upsell does not count) from four leads, and 1,500 pounds over four leads.
    $conversion = collect($cards['conversion'])->firstWhere('brand.slug', 'ukph');
    expect($conversion['won'])->toBe(1)
        ->and($conversion['rate'])->toBe(25.0)
        ->and($conversion['perLead'])->toBe(375.0)
        ->and($conversion['currency'])->toBe('GBP');

    // 4. Only the chat whose last message came from the person, inside the reply window.
    expect($cards['chats'])->toHaveCount(1)
        ->and($cards['chats'][0]['chats'])->toBe(1)
        ->and($cards['chats'][0]['brand']->slug)->toBe('ukph');

    // 5. The quiet website and the one whose check failed; never a paused one.
    expect(array_map(fn (array $row): string => $row['site']->domain, $cards['sites']))->toBe(['quiet.example', 'broken.example'])
        ->and($cards['sites'][1]['failed'])->toBeTrue();

    // 6. The seller's sales and money, in the brand's currency.
    expect($cards['sellers'])->toHaveCount(1)
        ->and($cards['sellers'][0]['sales'])->toBe(2)
        ->and($cards['sellers'][0]['revenue'])->toBe(1500.0);

    // 7. Wednesday 09:00 UTC is in the grid (this viewer has no time zone of their own, so UTC).
    expect($cards['hours']['total'])->toBe(5)
        ->and($cards['hours']['grid'][2][9])->toBe(1);

    // 8. One due today, one overdue.
    expect($cards['followUps'])->toHaveCount(1)
        ->and($cards['followUps'][0]['brand']->slug)->toBe('ukph')
        ->and($cards['followUps'][0]['today'])->toBe(1)
        ->and($cards['followUps'][0]['overdue'])->toBe(1);
});

it('says a wait in words', function (): void {
    expect(OverviewCards::duration(14))->toBe('14 min')
        ->and(OverviewCards::duration(130))->toBe('2 h 10 min')
        ->and(OverviewCards::duration(120))->toBe('2 h')
        ->and(OverviewCards::duration(4320))->toBe('3 days');
});
