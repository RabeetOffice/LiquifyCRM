<?php

declare(strict_types=1);

use App\Modules\Brands\Livewire\Admin\BrandWizard;
use App\Modules\Brands\Models\Brand;
use App\Modules\Core\Livewire\WelcomeTour;
use App\Modules\Core\Support\DashboardCache;
use App\Modules\Leads\Actions\CreateLead;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Week 6: cached dashboard numbers, the Add brand wizard, the welcome tour,
 * the PWA manifest and the contact-reveal endpoint.
 */

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->admin = admin();

    inBrand($this->brand);
});

it('caches dashboard numbers for a minute and refreshes them when a lead arrives', function (): void {
    $calls = 0;
    $count = function () use (&$calls): int {
        $calls++;

        return Lead::query()->count();
    };

    $brandId = (int) $this->brand->getKey();

    expect(DashboardCache::remember($brandId, 'test', $count))->toBe(0);

    // A row written behind the application's back is not seen within the minute.
    Lead::factory()->create(['brand_id' => $brandId]);
    expect(DashboardCache::remember($brandId, 'test', $count))->toBe(0)->and($calls)->toBe(1);

    // A lead arriving through the application refreshes the numbers at once.
    app(CreateLead::class)->handle($this->brand, ['name' => 'Sarah Lee', 'email' => 'sarah@example.com']);
    expect(DashboardCache::remember($brandId, 'test', $count))->toBe(2)->and($calls)->toBe(2);

    // After 60 seconds it is counted again anyway.
    Lead::factory()->create(['brand_id' => $brandId]);
    $this->travel(61)->seconds();
    expect(DashboardCache::remember($brandId, 'test', $count))->toBe(3);

    $this->actingAs($this->admin)->get('/b/ukph')->assertOk();
});

it('adds a brand in three steps with a check at the end', function (): void {
    Http::fake([
        'newbrand.test/icon.png' => Http::response('png', 200, ['Content-Type' => 'image/png']),
        'newbrand.test/*' => Http::response('<html><head><meta name="theme-color" content="#0F766E"><link rel="icon" href="/icon.png"></head></html>'),
    ]);

    $this->actingAs($this->admin)->get('/admin/brands/create')->assertOk()->assertSee('The basics');

    Livewire::actingAs($this->admin)
        ->test(BrandWizard::class)
        ->set('name', 'New Brand')
        ->assertSet('slug', 'new-brand')
        ->set('website', 'newbrand.test')
        ->call('createBrand')
        ->assertHasNoErrors()
        ->assertRedirect();

    $brand = Brand::query()->where('slug', 'new-brand')->sole();

    expect($brand->website)->toBe('https://newbrand.test')
        ->and($brand->colors['primary'])->toBe('#0F766E')
        ->and($brand->favicon_path)->not->toBeNull()
        ->and(LeadStatus::query()->forBrand($brand)->count())->toBe(6);

    Livewire::actingAs($this->admin)
        ->test(BrandWizard::class, ['brandId' => $brand->getKey()])
        ->assertSet('step', 2)
        ->assertSet('primaryColor', '#0F766E')
        ->set('secondaryColor', '#111827')
        ->call('saveLook')
        ->assertSet('step', 3)
        ->assertSee('6 statuses ready')
        ->assertSee('The website answers')
        ->assertSee('Create its first department');

    expect($brand->refresh()->colors)->toMatchArray(['primary' => '#0F766E', 'secondary' => '#111827']);

    $this->actingAs($this->manager)->get('/admin/brands/create')->assertForbidden();
});

it('shows the welcome tour once to a new Manager or Agent, never to Admins', function (): void {
    $this->actingAs($this->agent)->get('/b/ukph')->assertOk()->assertSee('Tip 1 of 3')->assertSee('Your leads are under My leads');

    Livewire::actingAs($this->agent)->test(WelcomeTour::class)->assertSet('open', true)->call('done')->assertSet('open', false);

    expect($this->agent->refresh()->tour_seen_at)->not->toBeNull();
    $this->actingAs($this->agent)->get('/b/ukph')->assertOk()->assertDontSee('Tip 1 of 3');

    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/ukph')->assertOk()->assertSee('New leads wait in your queue');

    $this->flushSession();
    $this->actingAs($this->admin)->get('/b/ukph')->assertOk()->assertDontSee('Tip 1 of 3');
});

it('offers add-to-home-screen through a web manifest', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest['name'])->toBe('LiquifyCRM')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toBe('/b')
        ->and(file_exists(public_path(ltrim($manifest['icons'][0]['src'], '/'))))->toBeTrue();

    $this->get('/login')->assertOk()->assertSee('manifest.webmanifest', false);
    $this->actingAs($this->admin)->get('/b/ukph')->assertSee('manifest.webmanifest', false);
});

it('reveals a contact through the endpoint, logged and limited per hour', function (): void {
    $this->brand->update(['settings' => ['reveal_limit_per_hour' => 1]]);
    $first = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'email' => 'sarah@example.com']);
    $second = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $notMine = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey()]);

    $this->actingAs($this->agent)->postJson('/b/ukph/leads/'.$first->getKey().'/reveal')->assertOk()->assertJson(['email' => 'sarah@example.com']);
    $this->actingAs($this->agent)->postJson('/b/ukph/leads/'.$second->getKey().'/reveal')->assertStatus(429);
    $this->actingAs($this->agent)->postJson('/b/ukph/leads/'.$notMine->getKey().'/reveal')->assertForbidden();

    expect($first->activities()->where('event', 'leads.contact_revealed')->count())->toBe(1);
});
