<?php

declare(strict_types=1);

use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadStatus;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * The redesigned working screens (12 October): filter chips and the slide-over
 * on the leads list, the pipeline path on a lead, the owner filter and the
 * slide-over on the pipeline, and the three-pane inbox with its filters.
 */

beforeEach(function (): void {
    Notification::fake();

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->admin = admin();

    inBrand($this->brand);

    $this->status = fn (string $slug): LeadStatus => LeadStatus::query()->forBrand($this->brand)->where('slug', $slug)->firstOrFail();
});

it('shows the filters in use as chips and takes one away at a time', function (): void {
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Owned Lead']);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Waiting Lead']);

    Livewire::actingAs($this->admin)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->set('assigned', 'no')
        ->set('status', (string) ($this->status)('new')->getKey())
        ->assertSee('Unassigned only')
        ->assertSee('Status: New')
        ->assertSee('Waiting Lead')
        ->assertDontSee('Owned Lead')
        ->call('removeFilter', 'assigned')
        ->assertSet('assigned', '')
        ->assertDontSee('Unassigned only')
        ->assertSee('Status: New')
        ->assertSee('Owned Lead')
        ->call('removeFilter', 'brand')
        ->assertSet('status', (string) ($this->status)('new')->getKey());
});

it('opens a lead in the slide-over and quietly closes it when the lead is out of reach', function (): void {
    $mine = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Sarah Lee']);
    $other = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => department($this->brand, ['name' => 'Publishing'])->getKey(), 'name' => 'Someone Else']);

    Livewire::actingAs($this->agent)
        ->test(LeadsIndex::class, ['brand' => $this->brand])
        ->call('openLead', $mine->getKey())
        ->assertSee('sheet-lead-'.$mine->getKey(), false)
        ->call('openLead', $other->getKey())
        ->assertSet('open', null)
        // The slide-over itself stays in the page (it opens at once on a click); only the lead inside goes.
        ->assertSee('lq-sheet-wide', false)
        ->assertDontSee('sheet-lead-', false);

    // A link to a lead that no longer exists opens the list, not an error page.
    $this->actingAs($this->agent)->get('/b/ukph/leads?open=999999')->assertOk()->assertSee("Your team's leads");
});

it('moves a lead with one click on the pipeline path and sends Won to the sale sheet', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);

    $panel = Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertSee('Move to Qualified')
        ->call('moveTo', ($this->status)('qualified')->getKey())
        ->assertDispatched('lead-updated');

    expect($lead->fresh()->status->slug)->toBe('qualified');

    $panel->call('moveTo', ($this->status)('won')->getKey())->assertSet('showWon', true);

    expect($lead->fresh()->status->slug)->toBe('qualified');
});

it('keeps the pipeline path closed to people who may not change the lead', function (): void {
    $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey()]);
    $stranger = agentOf(department($this->brand, ['name' => 'Consultants']));

    Livewire::actingAs($stranger)
        ->test(LeadPanel::class, ['leadId' => $lead->getKey()])
        ->assertForbidden();

    expect($lead->fresh()->status->slug)->toBe('new');
});

it('narrows the pipeline to one owner and opens a card in the slide-over', function (): void {
    $owned = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Owned Lead']);
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'name' => 'Waiting Lead']);

    Livewire::actingAs($this->manager)
        ->test(Pipeline::class, ['brand' => $this->brand])
        ->assertSee('Owned Lead')
        ->assertSee('Waiting Lead')
        ->set('owner', (string) $this->agent->getKey())
        ->assertSee('Owned Lead')
        ->assertDontSee('Waiting Lead')
        ->set('owner', 'none')
        ->assertSee('Waiting Lead')
        ->assertDontSee('Owned Lead')
        ->set('owner', '')
        ->call('openLead', $owned->getKey())
        ->assertSee('lq-sheet-wide', false)
        ->call('move', $owned->getKey(), ($this->status)('won')->getKey())
        ->assertSet('open', $owned->getKey());

    expect($owned->fresh()->status->slug)->toBe('new');
});

it('marks open leads nobody has touched for a week as quiet', function (): void {
    $quiet = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'name' => 'Quiet Lead']);
    $quiet->forceFill(['last_activity_at' => now()->subDays(9)])->saveQuietly();
    Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'name' => 'Fresh Lead']);

    Livewire::actingAs($this->manager)
        ->test(Pipeline::class, ['brand' => $this->brand])
        ->assertViewHas('quiet', [$quiet->getKey()])
        ->assertSee('gone quiet');
});

it('filters the inbox to unread chats and to chats that can still be answered', function (): void {
    $page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token'])->refresh();

    $chat = function (string $name, int $unread, int $hoursAgo) use ($page): Conversation {
        $lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => $name]);

        $conversation = Conversation::query()->create([
            'brand_id' => $this->brand->getKey(), 'lead_id' => $lead->getKey(), 'contact_external_id' => 'psid-'.$name, 'contact_name' => $name,
            'meta_source_id' => $page->getKey(), 'last_inbound_at' => now()->subHours($hoursAgo), 'last_message_at' => now()->subHours($hoursAgo),
            'last_message_preview' => 'Hello from '.$name, 'unread_count' => $unread,
        ]);

        Message::query()->create(['conversation_id' => $conversation->getKey(), 'direction' => 'in', 'body' => 'Hello from '.$name, 'external_id' => 'mid.'.$name]);

        return $conversation;
    };

    $fresh = $chat('Amy Wong', 2, 1);
    $chat('Ben Old', 0, 30);

    Livewire::actingAs($this->agent)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->assertSee('Amy Wong')
        ->assertSee('Ben Old')
        ->set('filter', 'unread')
        ->assertSee('Amy Wong')
        ->assertDontSee('Ben Old')
        ->set('filter', 'open')
        ->assertSee('Amy Wong')
        ->assertDontSee('Ben Old')
        ->set('filter', 'all')
        ->call('open', $fresh->getKey())
        ->assertSet('chat', (string) $fresh->getKey())
        ->assertSee('Open lead')
        ->call('closeChat')
        ->assertSet('chat', '');
});
