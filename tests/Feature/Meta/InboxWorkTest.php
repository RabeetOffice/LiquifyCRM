<?php

declare(strict_types=1);

use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Working a chat without leaving it: who the person is (name and picture
 * from Messenger), handing the lead to an Agent, and filling in what the
 * person tells you.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['liquify.meta.app_id' => '1234567890', 'liquify.meta.app_secret' => 'app-secret']);

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department, ['name' => 'Vimal Agent']);
    $this->otherAgent = agentOf($this->department, ['name' => 'Second Agent']);
    $this->admin = admin();
    $this->page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token'])->refresh();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => null, 'name' => 'Messenger contact', 'email' => null, 'phone' => null]);
    $this->conversation = Conversation::query()->create([
        'brand_id' => $this->brand->getKey(), 'lead_id' => $this->lead->getKey(), 'contact_external_id' => '777',
        'meta_source_id' => $this->page->getKey(), 'last_inbound_at' => now()->subHour(), 'last_message_at' => now()->subHour(), 'last_message_preview' => 'Hey', 'unread_count' => 1,
    ]);
    Message::query()->create(['conversation_id' => $this->conversation->getKey(), 'direction' => 'in', 'body' => 'Hey', 'external_id' => 'mid.1']);
});

it('lets a Manager hand the lead of a chat to an Agent from the inbox, and take it back', function (): void {
    Livewire::actingAs($this->manager)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->call('open', $this->conversation->getKey())
        ->assertSee('Assigned to')
        ->assertSee('Vimal Agent')
        ->call('assignLead', (string) $this->agent->getKey());

    expect($this->lead->fresh()->assigned_to)->toBe($this->agent->getKey());
    $this->assertDatabaseHas('activity_log', ['event' => 'leads.assigned', 'subject_id' => $this->lead->getKey()]);

    Livewire::actingAs($this->manager)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->call('open', $this->conversation->getKey())
        ->call('assignLead', '');

    expect($this->lead->fresh()->assigned_to)->toBeNull();
});

it('does not let an Agent assign, nor anyone assign to a person outside the brand', function (): void {
    $this->lead->update(['assigned_to' => $this->agent->getKey()]);

    Livewire::actingAs($this->agent)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->call('open', $this->conversation->getKey())
        ->assertDontSee('Assigned to')
        ->call('assignLead', (string) $this->otherAgent->getKey())
        ->assertForbidden();

    $stranger = agentOf(department(brand(['slug' => 'anz'])));

    Livewire::actingAs($this->manager)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->call('open', $this->conversation->getKey())
        ->call('assignLead', (string) $stranger->getKey())
        ->assertForbidden();

    expect($this->lead->fresh()->assigned_to)->toBe($this->agent->getKey());
});

it('fills in what the person told you from the chat, and the chat takes the new name', function (): void {
    Livewire::actingAs($this->manager)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->call('open', $this->conversation->getKey())
        ->call('openDetails')
        ->assertSet('showDetails', true)
        ->assertSet('details.name', 'Messenger contact')
        ->set('details.name', 'Amy Wong')
        ->set('details.email', 'Amy@Example.com')
        ->set('details.phone', '+44 7700 900123')
        ->set('details.company', 'Wong Books Ltd')
        ->set('details.address', "12 High Street\nLeeds")
        ->set('details.service', 'Book publishing')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertSet('showDetails', false)
        ->assertSee('Amy Wong')
        ->assertSee('Wong Books Ltd');

    $lead = $this->lead->fresh();

    expect($lead->name)->toBe('Amy Wong')
        ->and($lead->email)->toBe('amy@example.com')
        ->and($lead->phone)->toBe('+44 7700 900123')
        ->and($lead->fields)->toMatchArray(['company' => 'Wong Books Ltd', 'address' => "12 High Street\nLeeds", 'service' => 'Book publishing'])
        ->and($this->conversation->fresh()->contact_name)->toBe('Amy Wong');

    $this->assertDatabaseHas('activity_log', ['event' => 'leads.details_updated', 'subject_id' => $lead->getKey()]);
});

it('asks for a name and a real email, and offers the same dialog on the lead itself', function (): void {
    Livewire::actingAs($this->manager)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->assertSee('Edit details')
        ->call('openDetails')
        ->set('details.name', '')
        ->set('details.email', 'not-an-email')
        ->call('saveDetails')
        ->assertHasErrors(['details.name', 'details.email'])
        ->set('details.name', 'Amy Wong')
        ->set('details.email', '')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($this->lead->fresh()->name)->toBe('Amy Wong');
});

it('never shows a masked email or phone in the dialog, and leaves them alone unless one is typed', function (): void {
    $this->lead->update(['assigned_to' => $this->agent->getKey(), 'email' => 'secret@example.com', 'phone' => '+447700900999']);

    Livewire::actingAs($this->agent)
        ->test(LeadPanel::class, ['leadId' => $this->lead->getKey()])
        ->call('openDetails')
        ->assertSet('detailsContactVisible', false)
        ->assertSet('details.email', '')
        ->assertSet('details.phone', '')
        ->set('details.company', 'Wong Books Ltd')
        ->call('saveDetails')
        ->assertHasNoErrors();

    $lead = $this->lead->fresh();

    expect($lead->email)->toBe('secret@example.com')
        ->and($lead->phone)->toBe('+447700900999')
        ->and($lead->fields['company'])->toBe('Wong Books Ltd');
});

it('takes the name and picture Messenger shares, and renames a lead that had no name', function (): void {
    Http::fake(['graph.facebook.com/*/777*' => Http::response(['first_name' => 'Amy', 'last_name' => 'Wong', 'profile_pic' => 'https://platform-lookaside.fbsbx.com/pic/777.jpg'])]);

    $event = InboundEvent::query()->create(['source' => ProcessMetaEvent::MESSAGE, 'external_id' => 'mid.2', 'payload' => [
        'page_id' => '111', 'sender' => ['id' => '777'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'mid.2', 'text' => 'Are you there?'],
    ]]);

    ProcessMetaEvent::dispatchSync((int) $event->getKey());

    $conversation = $this->conversation->fresh();

    expect($conversation->contact_name)->toBe('Amy Wong')
        ->and($conversation->pictureUrl())->toBe('https://platform-lookaside.fbsbx.com/pic/777.jpg')
        ->and($conversation->contact_synced_at)->not->toBeNull()
        ->and($this->lead->fresh()->name)->toBe('Amy Wong');

    Livewire::actingAs($this->manager)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->call('open', $conversation->getKey())
        ->assertSee('https://platform-lookaside.fbsbx.com/pic/777.jpg', false)
        ->assertSee('business.facebook.com/latest/inbox/all?asset_id=111', false);
});

it('keeps a name typed by hand and the chat working when Messenger shares nothing', function (): void {
    $this->conversation->update(['contact_name' => 'Typed By Hand']);
    Http::fake(['graph.facebook.com/*/777*' => Http::response(['error' => ['message' => 'Insufficient permission to access user profile.', 'code' => 230]], 400)]);

    $event = InboundEvent::query()->create(['source' => ProcessMetaEvent::MESSAGE, 'external_id' => 'mid.3', 'payload' => [
        'page_id' => '111', 'sender' => ['id' => '777'], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'mid.3', 'text' => 'Hello again'],
    ]]);

    ProcessMetaEvent::dispatchSync((int) $event->getKey());

    $conversation = $this->conversation->fresh();

    expect($conversation->contact_name)->toBe('Typed By Hand')
        ->and($conversation->pictureUrl())->toBeNull()
        ->and($conversation->last_message_preview)->toBe('Hello again')
        ->and($event->fresh()->status->value)->toBe('done');
});
