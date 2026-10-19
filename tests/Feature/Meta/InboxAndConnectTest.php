<?php

declare(strict_types=1);

use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Enums\MessageStatus;
use App\Modules\Meta\Jobs\UpdateMessageReceipts;
use App\Modules\Meta\Livewire\Admin\IntegrationsPage;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaConnection;
use App\Modules\Meta\Models\MetaSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Week 4: Connect Facebook, Page mapping, the Inbox with the 24-hour reply
 * window, replies and the token refresh.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['liquify.meta.app_id' => '1234567890', 'liquify.meta.app_secret' => 'app-secret', 'liquify.meta.verify_token' => 'verify-me']);

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->other = brand(['name' => 'ANZ Publishing House', 'slug' => 'anz']);
    $this->department = department($this->brand, ['name' => 'Sales Team A']);
    $this->manager = managerOf($this->department);
    $this->agent = agentOf($this->department);
    $this->otherAgent = agentOf($this->department);
    $this->admin = admin();
    $this->page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token'])->refresh();

    inBrand($this->brand);

    $this->lead = Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'department_id' => $this->department->getKey(), 'assigned_to' => $this->agent->getKey(), 'name' => 'Amy Wong']);
    $this->conversation = Conversation::query()->create([
        'brand_id' => $this->brand->getKey(), 'lead_id' => $this->lead->getKey(), 'contact_external_id' => '777', 'contact_name' => 'Amy Wong',
        'meta_source_id' => $this->page->getKey(), 'last_inbound_at' => now()->subHour(), 'last_message_at' => now()->subHour(), 'last_message_preview' => 'How much is publishing?', 'unread_count' => 1,
    ]);
    Message::query()->create(['conversation_id' => $this->conversation->getKey(), 'direction' => 'in', 'body' => 'How much is publishing?', 'external_id' => 'mid.1']);
});

it('connects Facebook, saves the token encrypted and subscribes every Page', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::sequence()
            ->push(['access_token' => 'short-token'])
            ->push(['access_token' => 'long-token', 'expires_in' => 5184000]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'UKPH Page (renamed)', 'access_token' => 'new-page-token'],
            ['id' => '222', 'name' => 'ANZ Page', 'access_token' => 'anz-page-token'],
        ]]),
        'graph.facebook.com/*/me*' => Http::response(['id' => '42', 'name' => 'Rabeet']),
        'graph.facebook.com/*/subscribed_apps' => Http::response(['success' => true]),
    ]);

    $redirect = $this->actingAs($this->admin)->get('/integrations/facebook/connect')->assertRedirect();
    $location = (string) $redirect->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://www.facebook.com/v26.0/dialog/oauth')
        ->and($query['client_id'])->toBe('1234567890')
        ->and($query['scope'])->toContain('leads_retrieval')->toContain('pages_messaging')
        ->and($location)->not->toContain('app-secret');

    $this->get('/integrations/facebook/callback?code=abc&state=forged')->assertRedirect('/admin/integrations');
    expect(MetaConnection::query()->count())->toBe(0);

    $this->get('/integrations/facebook/connect');
    $this->get('/integrations/facebook/callback?code=abc&state='.session('meta_oauth_state'))->assertRedirect('/admin/integrations');

    $connection = MetaConnection::query()->sole();

    expect($connection->fb_user_name)->toBe('Rabeet')
        ->and($connection->user_token)->toBe('long-token')
        ->and($connection->getRawOriginal('user_token'))->not->toContain('long-token')
        ->and($connection->expires_at->diffInDays(now(), true))->toBeGreaterThan(58)
        ->and(MetaSource::query()->count())->toBe(2)
        ->and($this->page->refresh()->name)->toBe('UKPH Page (renamed)')
        ->and($this->page->brand_id)->toBe($this->brand->getKey())
        ->and($this->page->access_token)->toBe('new-page-token')
        ->and($this->page->isSubscribed())->toBeTrue()
        ->and(MetaSource::query()->where('external_id', '222')->sole()->brand_id)->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/222/subscribed_apps') && str_contains((string) $request['subscribed_fields'], 'leadgen'));
});

it('keeps Integrations and Connect Facebook for Super Admins and Admins', function (): void {
    // Before connecting: only the three steps, nothing below them.
    $this->actingAs($this->admin)->get('/admin/integrations')->assertOk()->assertSee('Connect Facebook')->assertSee('Tell Meta where to send leads and messages')->assertDontSee('Your Pages')->assertDontSee('UKPH Page');

    MetaConnection::query()->create(['fb_user_id' => '42', 'fb_user_name' => 'Rabeet', 'user_token' => 'long-token']);

    $this->get('/admin/integrations')->assertOk()->assertSee('Your Pages')->assertSee('UKPH Page')->assertSee('Connected as')->assertDontSee('app-secret')->assertDontSee('page-token')->assertDontSee('long-token');
    $this->get('/admin/integrations?tab=websites')->assertOk()->assertSee('No website connected yet');
    $this->get('/admin/integrations?tab=received')->assertOk()->assertSee('Nothing received yet');

    $this->flushSession();
    $this->actingAs($this->manager)->get('/admin/integrations')->assertForbidden();
    $this->actingAs($this->manager)->get('/integrations/facebook/connect')->assertForbidden();
});

it('maps a Page to a brand and disconnects only after the password', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
    MetaConnection::query()->create(['fb_user_id' => '42', 'fb_user_name' => 'Rabeet', 'user_token' => 'long-token']);

    Livewire::actingAs($this->admin)
        ->test(IntegrationsPage::class)
        ->call('mapPage', $this->page->getKey(), (string) $this->other->getKey())
        ->call('confirmDisconnect')
        ->set('confirmPassword', 'wrong')
        ->call('runConfirmed')
        ->assertHasErrors('confirmPassword');

    expect($this->page->refresh()->brand_id)->toBe($this->other->getKey())
        ->and(MetaConnection::query()->count())->toBe(1);

    Livewire::actingAs($this->admin)
        ->test(IntegrationsPage::class)
        ->call('confirmDisconnect')
        ->set('confirmPassword', 'password')
        ->call('runConfirmed');

    expect(MetaConnection::query()->count())->toBe(0)
        ->and($this->page->refresh()->access_token)->toBeNull();
});

it('shows the Inbox only for leads a person may see', function (): void {
    $this->actingAs($this->agent)->get('/b/ukph/inbox')->assertOk()->assertSee('Amy Wong')->assertSee('How much is publishing?');

    // A teammate in front sales reads the chat but does not answer it, and leaves it unread for its owner.
    $this->flushSession();
    $this->conversation->forceFill(['unread_count' => 1])->save();
    $this->actingAs($this->otherAgent)->get('/b/ukph/inbox?chat='.$this->conversation->getKey())->assertOk()
        ->assertSee('How much is publishing?')
        ->assertSee($this->agent->name.' is on this chat, so only they reply.')
        ->assertDontSee('Write a reply');
    expect($this->conversation->fresh()->unread_count)->toBe(1);

    $this->flushSession();
    $stranger = agentOf(department($this->brand, ['name' => 'Publishing']));
    $this->actingAs($stranger)->get('/b/ukph/inbox')->assertOk()->assertDontSee('Amy Wong');
    $this->actingAs($stranger)->get('/b/ukph/inbox?chat='.$this->conversation->getKey())->assertOk()->assertDontSee('How much is publishing?');

    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/ukph/inbox?chat='.$this->conversation->getKey())->assertOk()->assertSee('Reply within');

    $this->flushSession();
    $stranger = agentOf(department($this->other));
    inBrand($this->brand);
    $this->actingAs($stranger)->get('/b/ukph/inbox')->assertNotFound();
});

it('sends a reply inside the 24-hour window and logs it on the lead', function (): void {
    Http::fake(['graph.facebook.com/*/111/messages' => Http::response(['recipient_id' => '777', 'message_id' => 'mid.out.1'])]);

    Livewire::actingAs($this->agent)
        ->test(ConversationThread::class, ['conversationId' => $this->conversation->getKey()])
        ->assertSee('How much is publishing?')
        ->set('reply', 'Hello Amy, packages start at 499.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('reply', '');

    $message = Message::query()->where('direction', 'out')->sole();

    expect($message->status)->toBe(MessageStatus::Sent)
        ->and($message->external_id)->toBe('mid.out.1')
        ->and($message->sent_by)->toBe($this->agent->getKey())
        ->and($this->conversation->refresh()->unread_count)->toBe(0)
        ->and($this->lead->refresh()->first_contacted_at)->not->toBeNull()
        ->and($this->lead->activities()->where('event', 'messages.reply_sent')->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request['recipient']['id'] === '777' && $request['message']['text'] === 'Hello Amy, packages start at 499.' && $request->hasHeader('Authorization', 'Bearer page-token'));

    UpdateMessageReceipts::dispatchSync('111', '777', 'read', (int) (now()->addMinute()->getTimestamp() * 1000));
    expect($message->refresh()->status)->toBe(MessageStatus::Read);
});

it('refuses a reply once the 24-hour window has closed', function (): void {
    Http::fake();
    $this->conversation->update(['last_inbound_at' => now()->subHours(25)]);

    Livewire::actingAs($this->agent)
        ->test(ConversationThread::class, ['conversationId' => $this->conversation->getKey()])
        ->assertSee('Reply window closed')
        ->set('reply', 'Still interested?')
        ->call('send')
        ->assertHasErrors('reply');

    expect(Message::query()->where('direction', 'out')->count())->toBe(0);
    Http::assertNothingSent();

    $this->actingAs($this->agent)
        ->postJson('/b/ukph/conversations/'.$this->conversation->getKey().'/messages', ['body' => 'Hello?'])
        ->assertStatus(422);
});

it('marks a reply as failed when Facebook refuses it, and blocks people who cannot see the lead', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'This person is not available right now.', 'code' => 551]], 400)]);

    $this->actingAs($this->agent)
        ->postJson('/b/ukph/conversations/'.$this->conversation->getKey().'/messages', ['body' => 'Hello Amy'])
        ->assertStatus(202);

    $message = Message::query()->where('direction', 'out')->sole();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->error)->toContain('not available')
        ->and($this->lead->activities()->where('event', 'messages.reply_failed')->count())->toBe(1);

    // A teammate sees the chat but may not answer it; somebody of another team does not even see it.
    $this->flushSession();
    $this->actingAs($this->otherAgent)
        ->postJson('/b/ukph/conversations/'.$this->conversation->getKey().'/messages', ['body' => 'Hi'])
        ->assertForbidden();

    Livewire::actingAs($this->otherAgent)
        ->test(ConversationThread::class, ['conversationId' => $this->conversation->getKey()])
        ->set('reply', 'Hi')
        ->call('send')
        ->assertForbidden();

    Livewire::actingAs(agentOf(department($this->brand, ['name' => 'Publishing'])))
        ->test(ConversationThread::class, ['conversationId' => $this->conversation->getKey()])
        ->assertForbidden();
});

it('renews a Facebook login token before it expires and leaves a System User token alone', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'renewed-token', 'expires_in' => 5184000]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [['id' => '111', 'name' => 'UKPH Page', 'access_token' => 'fresh-page-token']]]),
        'graph.facebook.com/*' => Http::response(['success' => true]),
    ]);

    $login = MetaConnection::query()->create(['fb_user_id' => '42', 'fb_user_name' => 'Rabeet', 'user_token' => 'old-token', 'expires_at' => now()->addDays(5)]);
    $system = MetaConnection::query()->create(['fb_user_id' => '99', 'fb_user_name' => 'CRM System User', 'user_token' => 'system-token', 'is_system_user' => true]);

    $this->artisan('meta:refresh-tokens')->assertSuccessful();

    expect($login->refresh()->user_token)->toBe('renewed-token')
        ->and($login->expires_at->isAfter(now()->addDays(50)))->toBeTrue()
        ->and($system->refresh()->user_token)->toBe('system-token')
        ->and($this->page->refresh()->access_token)->toBe('fresh-page-token');
});
