<?php

declare(strict_types=1);

use App\Modules\Leads\Livewire\App\FollowUps;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Leads\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The Week 2 screens: list with saved views, slide-over with masked contact
 * and Reveal, bulk assign, pipeline drag and drop, follow-ups.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->admin = admin();

    inBrand($this->brand);
});

it('lists only the leads a person may see, with masked contacts for Agents', function (): void {
    $mine = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Sarah Lee', 'phone' => '+447700900123', 'email' => 'sarah@example.com']);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'name' => 'Free Lead', 'email' => 'free@example.com']);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => department($this->brand, ['name' => 'Publishing'])->getKey(), 'name' => 'Someone Else']);

    // Front sales sees its team's leads, the free ones with a Take button, contacts masked.
    $this->actingAs($this->agent)
        ->get('/b/ukph/leads')
        ->assertOk()
        ->assertSee("Your team's leads")
        ->assertSee('Sarah Lee')
        ->assertSee('s•••@example.com')
        ->assertDontSee('sarah@example.com')
        ->assertSee('Free Lead')
        ->assertDontSee('free@example.com')
        ->assertSee('takeLead(', false)
        ->assertDontSee('Someone Else');

    $this->flushSession();

    $this->actingAs($this->admin)
        ->get('/b/ukph/leads?tab=unassigned')
        ->assertOk()
        ->assertSee('Someone Else')
        ->assertDontSee('Sarah Lee');
});

it('reveals a contact from the lead panel and shows the full details afterwards', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'phone' => '+447700900123', 'email' => 'sarah@example.com']);

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('+44 77•• •••123')
        ->assertDontSee('sarah@example.com')
        ->call('reveal')
        ->assertSee('sarah@example.com')
        ->assertSee('+447700900123');

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.contact_revealed', 'subject_id' => $lead->getKey()]);
});

it('lets a Manager assign from the list in bulk and an Agent change status from the panel', function (): void {
    $leads = Lead::factory()->count(2)->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey()]);

    Livewire::actingAs($this->manager)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('selected', $leads->pluck('id')->all())
        ->set('bulkAssignTo', (string) $this->agent->getKey())
        ->call('bulkAssign')
        ->assertSet('selected', []);

    expect(Lead::query()->where('assigned_to', $this->agent->getKey())->count())->toBe(2);

    $contacted = LeadStatus::query()->forBrand($this->brand)->where('slug', 'contacted')->firstOrFail();

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $leads[0]->getKey()])
        ->call('openStatus')
        ->set('statusTo', (string) $contacted->getKey())
        ->call('changeStatus')
        ->assertDispatched('lead-updated');

    expect($leads[0]->fresh()->status->slug)->toBe('contacted');
});

it('moves a lead along the pipeline by drag and drop and refuses leads a person may not touch', function (): void {
    $mine = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $colleagues = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => agentOf($this->department, ['name' => 'Bilal Khan'])->getKey(), 'name' => 'Their Lead']);
    $other = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => department($this->brand, ['name' => 'Publishing'])->getKey()]);
    $qualified = LeadStatus::query()->forBrand($this->brand)->where('slug', 'qualified')->firstOrFail();

    $board = Livewire::actingAs($this->agent)
        ->test(Pipeline::class, ['brand' => $this->brand])
        ->assertSee('Their Lead')
        ->call('move', $mine->getKey(), $qualified->getKey())
        ->assertHasNoErrors();

    // A colleague's card is on the board but stays where it is, with a word instead of an error.
    $board->call('move', $colleagues->getKey(), $qualified->getKey())
        ->assertDispatched('toast', title: 'Not your lead', body: 'Bilal Khan is on Their Lead.');

    $board->call('move', $other->getKey(), $qualified->getKey())
        ->assertForbidden();

    expect($mine->fresh()->status->slug)->toBe('qualified')
        ->and($colleagues->fresh()->status->slug)->toBe('new')
        ->and($other->fresh()->status->slug)->toBe('new');
});

it('completes a follow-up from the Follow-ups screen', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $task = Task::query()->create(['brand_id' => $this->brand->getKey(), 'lead_id' => $lead->getKey(), 'user_id' => $this->agent->getKey(), 'title' => 'Call back', 'due_at' => now()]);
    $lead->forceFill(['next_follow_up_at' => now()])->save();

    Livewire::actingAs($this->agent)
        ->test(FollowUps::class, ['brand' => $this->brand])
        ->assertSee('Call back')
        ->call('complete', $task->getKey());

    expect($task->fresh()->done_at)->not->toBeNull()
        ->and($lead->fresh()->next_follow_up_at)->toBeNull();
});

it('returns 404 for a lead of another brand even to its own Manager', function (): void {
    $other = brand(['slug' => 'anz']);
    inBrand($other);
    $foreign = Lead::factory()->create(['brand_id' => $other->getKey()]);
    inBrand($this->brand);

    $this->actingAs($this->manager)->get('/b/ukph/leads/'.$foreign->getKey())->assertNotFound();
    $this->actingAs($this->admin)->get('/b/anz/leads/'.$foreign->getKey())->assertOk();
});

it('says when a lead arrived: how long ago for three days, then the date, in the viewer\'s own time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC'));
    $this->admin->forceFill(['timezone' => 'Asia/Karachi'])->save();

    $fresh = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Fresh Lead', 'created_at' => '2026-10-19 06:00:00']);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Night Lead', 'created_at' => '2026-10-17 20:00:00']);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Older Lead', 'created_at' => '2026-10-14 09:30:00']);

    // Karachi is five hours ahead: 06:00 UTC is 11:00 there, and 20:00 on the 17th is already the 18th.
    Livewire::actingAs($this->admin)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->assertSeeInOrder(['Fresh Lead', '6h ago', 'Today, 11:00'])
        ->assertSeeInOrder(['Night Lead', '1d ago', 'Yesterday, 01:00'])
        ->assertSeeInOrder(['Older Lead', '14 Oct', 'Wed, 14:30'])
        ->assertSee('Wednesday 14 October 2026, 14:30 (PKT)');

    Livewire::actingAs($this->admin)
        ->test(LeadPanel::class, ['leadId' => $fresh->getKey()])
        ->assertSeeInOrder(['Received', '6h ago · Today, 11:00']);

    // Somebody who has not chosen a time zone sees the default one (London, an hour ahead of UTC in October).
    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'created_at' => '2026-10-14 09:30:00'])->getKey()])
        ->assertSeeInOrder(['Received', '14 Oct, 10:30']);
});
