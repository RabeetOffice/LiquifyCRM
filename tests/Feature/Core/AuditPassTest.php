<?php

declare(strict_types=1);

use App\Modules\Brands\Livewire\Admin\BrandsIndex;
use App\Modules\Departments\Livewire\App\DepartmentForm;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The audit pass (12 October): brands as cards with their totals, the
 * department form showing each person's real role, the System screen,
 * and error pages in LiquifyCRM's own words.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department, ['name' => 'Grace Manager']);
    $this->agent = agentOf($this->department, ['name' => 'Aisha Agent']);
    $this->admin = admin();

    inBrand($this->brand);
});

it('shows the brands as cards with their totals and narrows them to active or inactive', function (): void {
    $quiet = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz', 'is_active' => false]);
    Lead::factory()->count(3)->create(['brand_id' => $this->brand->getKey()]);
    Lead::factory()->create(['brand_id' => $quiet->getKey()]);

    Livewire::actingAs($this->admin)
        ->test(BrandsIndex::class)
        ->assertViewHas('summary', fn (array $summary): bool => $summary['brands'] === 2 && $summary['active'] === 1 && $summary['people'] === 2 && $summary['leads'] === 4)
        ->assertViewHas('leads', fn ($leads): bool => (int) $leads[$this->brand->getKey()] === 3 && (int) $leads[$quiet->getKey()] === 1)
        ->assertSee('1 of 2')
        ->assertSee('/b/ukph')
        ->assertSee('UK Publishing House')
        ->assertSee('ANZ Publishing House')
        ->set('active', '0')
        ->assertDontSee('/b/ukph')
        ->assertSee('/b/anz')
        ->set('search', 'nothing like this')
        ->assertSee('No brand matches')
        ->call('showAll')
        ->assertSet('active', '')
        ->assertSet('search', '')
        ->assertSee('/b/ukph');
});

it('shows each person in a department with the role they really hold', function (): void {
    $html = Livewire::actingAs($this->admin)
        ->test(DepartmentForm::class, ['brand' => $this->brand, 'department' => $this->department])
        ->assertSee('Grace Manager')
        ->assertSee('Aisha Agent')
        ->html();

    // One select per person: the Manager's has Manager chosen, the Agent's has Agent chosen.
    expect(substr_count($html, '<option value="manager" selected'))->toBe(1)
        ->and(substr_count($html, '<option value="agent" selected'))->toBe(1);
});

it('shows the System screen with its facts and health checks as a section', function (): void {
    $this->actingAs($this->admin)
        ->get('/admin/system')
        ->assertOk()
        ->assertSee('LiquifyCRM version')
        ->assertSee('Scheduler')
        ->assertSee('Last backup')
        ->assertSee('Health checks')
        ->assertSee('Cron, every minute')
        ->assertSee('Database is up to date');
});

it('answers a wrong address and a closed screen in its own words', function (): void {
    $this->get('/nothing-here')
        ->assertNotFound()
        ->assertSee('This page is not here')
        ->assertSee('Go to the start');

    $this->actingAs($this->agent)
        ->get('/admin/system')
        ->assertForbidden()
        ->assertSee('This screen is not open to you');

    $this->flushSession();
    auth()->logout();

    $this->get('/invite/not-a-real-token')
        ->assertSee('This invitation can')
        ->assertSee('The link is not valid')
        ->assertSee(route('login'), false);
});
