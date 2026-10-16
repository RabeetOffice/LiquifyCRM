<?php

declare(strict_types=1);

use App\Modules\Brands\Jobs\FetchBrandIdentity;
use App\Modules\Brands\Livewire\Admin\BrandForm;
use App\Modules\Brands\Livewire\App\MemberForm;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Actions\DistributeLeads;
use App\Modules\Sales\Livewire\App\DistributePage;
use App\Modules\Sales\Models\LeadDistribution;
use App\Modules\Sales\Models\Target;
use App\Modules\Sites\Jobs\ImportSiteHistory;
use App\Modules\Sites\Livewire\App\SiteShow;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Support\SiteConnector;
use App\Modules\Sites\Support\SiteDatabase;
use App\Modules\Sites\Support\SiteLeadNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Week 5: two-level lead distribution with its history, old-lead import from
 * a website, the brand favicon and colour fetch, and the target on Add person.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph', 'website' => 'https://ukph.test']);
    $this->teamA = department($this->brand, ['name' => 'Sales Team A']);
    $this->teamB = department($this->brand, ['name' => 'Consultants']);
    $this->manager = managerOf($this->teamA);
    $this->agentOne = agentOf($this->teamA, ['name' => 'Aisha Agent']);
    $this->agentTwo = agentOf($this->teamA, ['name' => 'Omar Other']);
    $this->admin = admin();

    inBrand($this->brand);
});

it('works out equal, percentage and count splits', function (): void {
    expect(DistributeLeads::allocate(10, [1 => null, 2 => null, 3 => null], 'equal'))->toBe([1 => 4, 2 => 3, 3 => 3])
        ->and(DistributeLeads::allocate(2000, [1 => 60, 2 => 40], 'percent'))->toBe([1 => 1200, 2 => 800])
        ->and(DistributeLeads::allocate(7, [1 => 50, 2 => 50], 'percent'))->toBe([1 => 4, 2 => 3])
        ->and(DistributeLeads::allocate(10, [1 => 30, 2 => 30], 'percent'))->toBe([1 => 3, 2 => 3])
        ->and(DistributeLeads::allocate(10, [1 => 6, 2 => 2], 'count'))->toBe([1 => 6, 2 => 2])
        ->and(fn () => DistributeLeads::allocate(10, [1 => 8, 2 => 5], 'count'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DistributeLeads::allocate(10, [1 => 80, 2 => 50], 'percent'))->toThrow(InvalidArgumentException::class);
});

it('lets the Admin split waiting leads across departments by percentage', function (): void {
    Lead::factory()->count(10)->create(['brand_id' => $this->brand->getKey()]);
    $already = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->teamB->getKey()]);

    Livewire::actingAs($this->admin)
        ->test(DistributePage::class, ['brand' => $this->brand])
        ->assertSee('Sales Team A')
        ->assertSee('Consultants')
        ->set('method', 'percent')
        ->set('include.'.$this->teamA->getKey(), true)
        ->set('include.'.$this->teamB->getKey(), true)
        ->set('amounts.'.$this->teamA->getKey(), '60')
        ->set('amounts.'.$this->teamB->getKey(), '40')
        ->call('distribute')
        ->assertHasNoErrors();

    expect(Lead::query()->where('department_id', $this->teamA->getKey())->count())->toBe(6)
        ->and(Lead::query()->where('department_id', $this->teamB->getKey())->count())->toBe(5)
        ->and($already->refresh()->department_id)->toBe($this->teamB->getKey())
        ->and(LeadDistribution::query()->count())->toBe(2)
        ->and(LeadDistribution::query()->where('to_id', $this->teamA->getKey())->sole()->lead_count)->toBe(6)
        ->and(LeadDistribution::query()->where('to_id', $this->teamA->getKey())->sole()->method)->toBe('percent');

    // One notification per department, not one per lead.
    Notification::assertSentToTimes($this->manager, AppNotification::class, 1);
});

it('lets a Manager share their department leads among their Agents, and nobody else', function (): void {
    Lead::factory()->count(5)->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->teamA->getKey()]);
    Lead::factory()->count(3)->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->teamB->getKey()]);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->teamA->getKey(), 'assigned_to' => $this->agentTwo->getKey()]);

    Livewire::actingAs($this->manager)
        ->test(DistributePage::class, ['brand' => $this->brand])
        ->assertSee('Aisha Agent')
        ->assertDontSee('By percentage')
        ->set('include.'.$this->agentOne->getKey(), true)
        ->set('include.'.$this->agentTwo->getKey(), true)
        ->call('distribute')
        ->assertHasNoErrors();

    expect(Lead::query()->where('assigned_to', $this->agentOne->getKey())->count())->toBe(3)
        ->and(Lead::query()->where('assigned_to', $this->agentTwo->getKey())->count())->toBe(3)
        ->and(Lead::query()->where('department_id', $this->teamB->getKey())->whereNotNull('assigned_to')->count())->toBe(0)
        ->and(LeadDistribution::query()->where('to_type', 'user')->count())->toBe(2);

    Notification::assertSentToTimes($this->agentOne, AppNotification::class, 1);

    $this->actingAs($this->agentOne)->get('/b/ukph/leads/distribute')->assertForbidden();
});

it('imports old leads of a website for a date range without notifying anyone per lead', function (): void {
    $site = Site::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'UKPH', 'pull_config' => ['driver' => 'database', 'database' => ['host' => 'db.test', 'database' => 'ukph', 'username' => 'r', 'password' => 'p', 'table' => 'leads']]])->refresh();
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'email' => 'known@example.com', 'name' => 'Known Person']);

    Queue::fake([ImportSiteHistory::class]);

    Livewire::actingAs($this->admin)
        ->test(SiteShow::class, ['brand' => $this->brand, 'site' => $site])
        ->assertSee('Import old leads')
        ->call('openImport')
        ->set('importFrom', '2026-01-01')
        ->set('importUntil', '2026-06-30')
        ->call('importHistory')
        ->assertHasNoErrors();

    Queue::assertPushed(ImportSiteHistory::class);

    $database = Mockery::mock(SiteDatabase::class);
    $database->shouldReceive('rows')->once()->andReturn([
        ['id' => 1, 'name' => 'Too Old', 'email' => 'old@example.com', 'created_at' => '2025-11-02 10:00:00'],
        ['id' => 2, 'name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'created_at' => '2026-02-10 09:30:00'],
        ['id' => 3, 'name' => 'Known Person', 'email' => 'known@example.com', 'created_at' => '2026-03-01 12:00:00'],
        ['id' => 4, 'name' => 'Tom Hall', 'phone' => '07700 900456', 'created_at' => '2026-06-30 23:10:00'],
    ]);
    app()->instance(SiteDatabase::class, $database);

    Notification::fake();
    (new ImportSiteHistory($site->getKey(), '2026-01-01', '2026-06-30', $this->admin->getKey()))->handle(...array_map(fn (string $class) => app($class), [
        SiteConnector::class, SiteDatabase::class, SiteLeadNormalizer::class, CreateLead::class,
    ]));

    $sarah = Lead::query()->where('email', 'sarah@example.com')->sole();

    expect(Lead::query()->count())->toBe(3)
        ->and($sarah->created_at->toDateString())->toBe('2026-02-10')
        ->and($sarah->imported_at)->not->toBeNull()
        ->and($sarah->external_id)->toBe($site->getKey().':2')
        ->and(Lead::query()->where('email', 'old@example.com')->exists())->toBeFalse();

    // Only the summary for the person who started it.
    Notification::assertCount(1);
    Notification::assertSentTo($this->admin, fn (AppNotification $notification): bool => str_contains($notification->body, '2 imported, 1 joined'));
});

it('fetches the favicon and colours from the brand website', function (): void {
    Http::fake([
        'ukph.test/icon-192.png' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        'ukph.test/site.webmanifest' => Http::response(['theme_color' => '#0a5', 'background_color' => '#ffffff']),
        'ukph.test/*' => Http::response('<html><head><meta name="theme-color" content="#1E3A8A"><link rel="icon" sizes="192x192" href="/icon-192.png"><link rel="manifest" href="/site.webmanifest"></head></html>'),
    ]);

    // The site's own colour becomes the second colour; the main one is the white behind the mark.
    Livewire::actingAs($this->admin)
        ->test(BrandForm::class, ['brandId' => $this->brand->getKey()])
        ->call('fetchIdentity')
        ->assertSet('primaryColor', '#FFFFFF')
        ->assertSet('secondaryColor', '#1E3A8A');

    $brand = $this->brand->refresh();

    expect($brand->favicon_path)->toBe('brands/favicons/fetched-'.$brand->getKey().'.png')
        ->and($brand->colors)->toMatchArray(['primary' => '#FFFFFF', 'secondary' => '#1E3A8A'])
        ->and($brand->primaryColor())->toBe('#1E3A8A')
        ->and($brand->identity_fetched_at)->not->toBeNull();

    Storage::disk('public')->assertExists($brand->favicon_path);

    $this->actingAs($this->manager)->postJson('/admin/brands/'.$brand->getKey().'/fetch-identity')->assertForbidden();

    // The weekly refresh keeps the favicon current and leaves the colours and the logo alone.
    Queue::fake();
    $this->artisan('brands:refresh-identity')->assertSuccessful();
    Queue::assertPushed(FetchBrandIdentity::class, fn (FetchBrandIdentity $job): bool => $job->brandId === $brand->getKey() && $job->withColors === false && $job->withLogo === false);
});

it('sets a monthly target while adding a person', function (): void {
    Livewire::actingAs($this->manager)
        ->test(MemberForm::class, ['brand' => $this->brand])
        ->set('name', 'Nadia New')
        ->set('email', 'nadia@example.com')
        ->set('password', 'a-strong-pass-123')
        ->set('role', 'agent')
        ->set('department', (string) $this->teamA->getKey())
        ->set('targetRevenue', '4000')
        ->set('targetCount', '5')
        ->call('save')
        ->assertHasNoErrors();

    $target = Target::query()->sole();

    expect($target->targetable_type)->toBe('user')
        ->and((float) $target->revenue_target)->toBe(4000.0)
        ->and($target->sales_count_target)->toBe(5)
        ->and($target->period)->toBe(now()->format('Y-m'));
});
