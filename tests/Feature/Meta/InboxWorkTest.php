<?php

declare(strict_types=1);

use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Actions\SyncContactProfile;
use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Livewire\App\ConversationThread;
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

it('adds a reply written on Facebook itself to the chat, and leaves out the echo of what LiquifyCRM sent', function (): void {
    $echo = fn (string $mid, string $text, ?string $appId): InboundEvent => InboundEvent::query()->create(['source' => ProcessMetaEvent::MESSAGE, 'external_id' => $mid, 'payload' => [
        'page_id' => '111', 'sender' => ['id' => '111'], 'recipient' => ['id' => '777'], 'timestamp' => now()->getTimestampMs(),
        'message' => array_filter(['mid' => $mid, 'text' => $text, 'is_echo' => true, 'app_id' => $appId]),
    ]]);

    ProcessMetaEvent::dispatchSync((int) $echo('mid.echo.1', 'We will call you today.', '263902037430900')->getKey());
    ProcessMetaEvent::dispatchSync((int) $echo('mid.echo.2', 'Sent from the CRM', '1234567890')->getKey());

    $sent = Message::query()->where('conversation_id', $this->conversation->getKey())->where('direction', 'out')->get();
    $conversation = $this->conversation->fresh();

    expect($sent)->toHaveCount(1)
        ->and($sent->first()->body)->toBe('We will call you today.')
        ->and($sent->first()->isFromFacebook())->toBeTrue()
        ->and($conversation->last_message_preview)->toBe('You: We will call you today.')
        ->and($conversation->unread_count)->toBe(0);

    Livewire::actingAs($this->manager)
        ->test(ConversationThread::class, ['conversationId' => $conversation->getKey(), 'inbox' => true])
        ->assertSee('We will call you today.')
        ->assertSee('Sent on Facebook');
});

it('shows the pictures and files a person sends, from where Facebook keeps them', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['first_name' => 'Amy', 'last_name' => 'Wong'])]);

    $event = InboundEvent::query()->create(['source' => ProcessMetaEvent::MESSAGE, 'external_id' => 'mid.pic', 'payload' => [
        'page_id' => '111', 'sender' => ['id' => '777'], 'timestamp' => now()->getTimestampMs(),
        'message' => ['mid' => 'mid.pic', 'attachments' => [
            ['type' => 'image', 'payload' => ['url' => 'https://scontent.xx.fbcdn.net/v/cover.jpg?oe=1']],
            ['type' => 'file', 'payload' => ['url' => 'https://cdn.fbsbx.com/v/manuscript.pdf?oe=1']],
            ['type' => 'image', 'payload' => ['sticker_id' => 1]],
        ]],
    ]]);

    ProcessMetaEvent::dispatchSync((int) $event->getKey());

    $message = Message::query()->where('external_id', 'mid.pic')->sole();

    expect($message->body)->toBe('[3 attachments]')
        ->and($message->attachments())->toHaveCount(2)
        ->and($message->attachments()[1])->toMatchArray(['type' => 'file', 'name' => 'manuscript.pdf'])
        ->and($message->isOnlyAttachments())->toBeTrue();

    Livewire::actingAs($this->manager)
        ->test(ConversationThread::class, ['conversationId' => $this->conversation->getKey(), 'inbox' => true])
        ->assertSee('https://scontent.xx.fbcdn.net/v/cover.jpg?oe=1', false)
        ->assertSee('manuscript.pdf')
        ->assertDontSee('[3 attachments]');
});

it('sends a reply inside the request, so it is Sent when the screen answers', function (): void {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['recipient_id' => '777', 'message_id' => 'mid.out.1'])]);

    Livewire::actingAs($this->manager)
        ->test(ConversationThread::class, ['conversationId' => $this->conversation->getKey(), 'inbox' => true])
        ->set('reply', 'Hello Amy')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('reply', '')
        ->assertSee('Hello Amy');

    $message = Message::query()->where('direction', 'out')->sole();

    expect($message->status->value)->toBe('sent')
        ->and($message->external_id)->toBe('mid.out.1')
        ->and($message->isFromFacebook())->toBeFalse();
});

it('asks Facebook again on request, finds the name through the Page chats, and says why when nothing comes', function (): void {
    Http::fake([
        'graph.facebook.com/*/777/picture*' => Http::response(['data' => ['url' => 'https://platform-lookaside.fbsbx.com/p/777.jpg', 'is_silhouette' => false]]),
        'graph.facebook.com/*/111/conversations*' => Http::response(['data' => [['participants' => ['data' => [['id' => '777', 'name' => 'Amy Wong'], ['id' => '111', 'name' => 'UKPH Page']]]]]]),
        'graph.facebook.com/*/777*' => Http::response(['error' => ['message' => 'Insufficient permission to access user profile.', 'code' => 230]], 400),
    ]);

    Livewire::actingAs($this->manager)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->call('open', $this->conversation->getKey())
        ->call('refreshProfile')
        ->assertSee('Amy Wong');

    $conversation = $this->conversation->fresh();

    expect($conversation->contact_name)->toBe('Amy Wong')
        ->and($conversation->pictureUrl())->toBe('https://platform-lookaside.fbsbx.com/p/777.jpg')
        ->and($this->lead->fresh()->name)->toBe('Amy Wong');
});

it('says in Facebook\'s own words why a profile was not shared', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Insufficient permission to access user profile.', 'code' => 230]], 400)]);

    $result = app(SyncContactProfile::class)->handle($this->conversation->fresh(), force: true);

    expect($result)->toMatchArray(['name' => false, 'picture' => false, 'reason' => 'Insufficient permission to access user profile.'])
        ->and($this->conversation->fresh()->contact_synced_at)->not->toBeNull();

    Livewire::actingAs($this->manager)
        ->test(Inbox::class, ['brand' => $this->brand])
        ->call('open', $this->conversation->getKey())
        ->call('refreshProfile')
        ->assertDispatched('toast');
});
