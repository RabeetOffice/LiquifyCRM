<?php

declare(strict_types=1);

use App\Modules\Brands\Actions\RemoveMemberFromBrand;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Navigation\Menu;
use App\Modules\Leads\Actions\MergeLeads;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsBin;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Models\Lead;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The bin: leads an Admin deleted wait there, with who deleted them, until
 * an Admin restores them. Merged leads are not in it.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->admin = admin(['name' => 'Ada Admin']);

    inBrand($this->brand);
});

/** Deletes leads the way the Leads screen does, so the bin sees a "deleted" line. */
function binLeads(mixed $test, Lead ...$leads): void
{
    Livewire::actingAs($test->admin)
        ->test(LeadsIndex::class, ['brand' => $test->brand])
        ->set('selected', array_map(fn (Lead $lead): int => (int) $lead->getKey(), $leads))
        ->call('bulkDelete');
}

it('shows the deleted leads to an Admin, with who deleted them', function (): void {
    $gone = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Deleted Dana']);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Living Lou']);

    binLeads($this, $gone);

    $this->actingAs($this->admin)
        ->get('/b/ukph/leads/bin')
        ->assertOk()
        ->assertSee('Bin')
        ->assertSee('Deleted Dana')
        ->assertSee('by Ada Admin')
        ->assertDontSee('Living Lou');
});

it('keeps Managers and Agents out of the bin', function (): void {
    $this->actingAs($this->manager)->get('/b/ukph/leads/bin')->assertForbidden();

    $this->flushSession();

    $this->actingAs($this->agent)->get('/b/ukph/leads/bin')->assertForbidden();
});

it('puts the Bin in the menu and on Leads for Admins only', function (): void {
    $labels = fn ($user): array => collect(Menu::for($user, $this->brand, 'brand.leads.index'))->flatMap(fn (array $group): array => array_column($group['items'], 'label'))->all();

    expect($labels($this->admin))->toContain('Bin')
        ->and($labels($this->manager))->not->toContain('Bin');

    Livewire::actingAs($this->admin)->test(LeadsIndex::class, ['brand' => $this->brand])->assertSee('/b/ukph/leads/bin');
    Livewire::actingAs($this->manager)->test(LeadsIndex::class, ['brand' => $this->brand])->assertDontSee('/b/ukph/leads/bin');
});

it('restores a lead with its owner, and logs it', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Back Again']);

    binLeads($this, $lead);
    expect(Lead::query()->find($lead->getKey()))->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->assertSee('Back Again')
        ->call('restore', $lead->getKey())
        ->assertDontSee('Back Again');

    $restored = Lead::query()->findOrFail($lead->getKey());

    expect($restored->assigned_to)->toBe($this->agent->getKey())
        ->and($restored->department_id)->toBe($this->department->getKey());

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.restored', 'subject_id' => $lead->getKey(), 'causer_id' => $this->admin->getKey()]);
});

it('sends a restored lead to the queue when its owner has left the brand', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);

    binLeads($this, $lead);

    /** @var Membership $membership */
    $membership = $this->agent->membershipFor($this->brand);
    app(RemoveMemberFromBrand::class)->handle($membership, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->call('restore', $lead->getKey());

    $restored = Lead::query()->findOrFail($lead->getKey());

    expect($restored->assigned_to)->toBeNull()
        ->and($restored->department_id)->toBe($this->department->getKey());
});

it('restores every ticked lead at once', function (): void {
    $leads = Lead::factory()->count(3)->create(['brand_id' => $this->brand->getKey()]);

    binLeads($this, ...$leads->all());
    expect(Lead::query()->count())->toBe(0);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->set('selected', $leads->take(2)->pluck('id')->all())
        ->call('restoreSelected')
        ->assertSet('selected', []);

    expect(Lead::query()->count())->toBe(2)
        ->and(Lead::query()->inBin()->count())->toBe(1);
});

it('leaves out a lead that was merged into another, and will not restore it', function (): void {
    $keep = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Kept Kim']);
    $other = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Merged Max']);

    app(MergeLeads::class)->handle($keep, $other, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->assertSee('The bin is empty')
        ->assertDontSee('Merged Max');

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->call('restore', $other->getKey())
        ->assertNotFound();

    expect(Lead::query()->find($other->getKey()))->toBeNull();
});

it('never shows or restores a lead of another brand', function (): void {
    $otherBrand = brand(['slug' => 'other']);
    $foreign = Lead::factory()->create(['brand_id' => $otherBrand->getKey(), 'name' => 'Foreign Fay']);

    inBrand($otherBrand);
    binLeads((object) ['admin' => $this->admin, 'brand' => $otherBrand], $foreign);
    inBrand($this->brand);

    Livewire::actingAs($this->admin)
        ->test(LeadsBin::class, ['brand' => $this->brand])
        ->assertDontSee('Foreign Fay')
        ->call('restore', $foreign->getKey())
        ->assertNotFound();

    expect(Lead::query()->forBrand($otherBrand)->inBin()->count())->toBe(1);
});

it('points an Admin from a deleted lead to the bin', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Gone Gus']);

    // Open on one screen, deleted on another.
    $panel = Livewire::actingAs($this->admin)->test(LeadPanel::class, ['leadId' => $lead->getKey()]);

    binLeads($this, $lead);

    $panel->call('$refresh')
        ->assertSee('This lead is in the bin')
        ->assertSee('Open the Bin');
});
