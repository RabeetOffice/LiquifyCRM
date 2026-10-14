<?php

declare(strict_types=1);

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Livewire\App\LeadPanel;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Actions\ConnectFacebook;
use App\Modules\Meta\Enums\MessageStatus;
use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaConnection;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Instagram direct messages: the same Meta app and webhook as Messenger. An
 * Instagram account belongs to the brand of the Page it is linked to, and its
 * chats live in the same Inbox.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['liquify.meta.app_id' => '1234567890', 'liquify.meta.app_secret' => 'app-secret', 'liquify.meta.verify_token' => 'verify-me']);

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->page = MetaSource::query()->create([
        'brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token',
        'instagram_id' => '17841400111', 'instagram_username' => 'ukph.books',
    ])->refresh();

    Http::fake([
        'graph.facebook.com/*/5550001*' => Http::response(['name' => 'Zara Khan', 'username' => 'zara.reads', 'profile_pic' => 'https://cdn.example.com/zara.jpg']),
        'graph.facebook.com/*/111/messages' => Http::response(['recipient_id' => '5550001', 'message_id' => 'ig.out.1']),
    ]);
});

/**
 * Post a webhook the way Meta does, signed with the app secret.
 *
 * @param  array<string, mixed>  $payload
 */
function instagramWebhook(array $payload)
{
    $body = (string) json_encode($payload);

    return test()->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
    ], $body);
}

/**
 * @param  array<string, mixed>  $message
 * @return array<string, mixed>
 */
function instagramDm(array $message, string $from = '5550001', string $to = '17841400111', ?int $at = null): array
{
    return ['object' => 'instagram', 'entry' => [['id' => $to === '5550001' ? $from : $to, 'time' => time(), 'messaging' => [[
        'sender' => ['id' => $from],
        'recipient' => ['id' => $to],
        'timestamp' => ($at ?? time()) * 1000,
        'message' => $message,
    ]]]]];
}

it('turns the first Instagram message into a lead and a chat of the Page brand', function (): void {
    instagramWebhook(instagramDm(['mid' => 'ig.1', 'text' => 'Hi! Do you publish poetry?', 'referral' => [
        'ref' => 'ad', 'ad_id' => '120200', 'source' => 'ADS', 'type' => 'OPEN_THREAD', 'ads_context_data' => ['ad_title' => 'Autumn poetry offer'],
    ]]))->assertOk()->assertSee('EVENT_RECEIVED');

    $event = InboundEvent::query()->sole();
    $conversation = Conversation::query()->acrossBrands()->sole();
    $lead = Lead::query()->forBrand($this->brand)->sole();

    expect($event->source)->toBe(ProcessMetaEvent::INSTAGRAM)
        ->and($event->status)->toBe(InboundStatus::Done)
        ->and($event->brand_id)->toBe($this->brand->getKey())
        ->and($conversation->channel)->toBe(Conversation::CHANNEL_INSTAGRAM)
        ->and($conversation->contact_external_id)->toBe('5550001')
        ->and($conversation->contact_name)->toBe('Zara Khan')
        ->and($conversation->handle())->toBe('zara.reads')
        ->and($conversation->profileUrl())->toBe('https://www.instagram.com/zara.reads/')
        ->and($conversation->pictureUrl())->toBe('https://cdn.example.com/zara.jpg')
        ->and($conversation->lead_id)->toBe($lead->getKey())
        ->and($conversation->canReply())->toBeTrue()
        ->and($lead->source_type)->toBe(LeadSource::Instagram)
        ->and($lead->name)->toBe('Zara Khan')
        ->and($lead->message)->toBe('Hi! Do you publish poetry?')
        ->and($lead->fields)->toMatchArray(['instagram' => '@ukph.books', 'ad' => 'Autumn poetry offer', 'ad_id' => '120200'])
        ->and(Message::query()->sole()->direction)->toBe('in');

    instagramWebhook(instagramDm(['mid' => 'ig.2', 'text' => 'Still there?']))->assertOk();
    instagramWebhook(instagramDm(['mid' => 'ig.2', 'text' => 'Still there?']))->assertOk();

    expect(Conversation::query()->acrossBrands()->count())->toBe(1)
        ->and(Lead::query()->forBrand($this->brand)->count())->toBe(1)
        ->and(Message::query()->count())->toBe(2)
        ->and(Conversation::query()->acrossBrands()->sole()->unread_count)->toBe(2);
});

it('keeps a message for an account that is not linked yet and processes it once Refresh Pages links it', function (): void {
    $this->page->forceFill(['instagram_id' => null, 'instagram_username' => null])->save();

    instagramWebhook(instagramDm(['mid' => 'ig.wait', 'text' => 'Hello?']))->assertOk();

    $event = InboundEvent::query()->sole();

    expect($event->status)->toBe(InboundStatus::Failed)
        ->and($event->error)->toContain('not linked to a connected Facebook Page')
        ->and(Lead::query()->acrossBrands()->count())->toBe(0);

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [['id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token']]]),
        'graph.facebook.com/*/111/subscribed_apps' => Http::response(['success' => true]),
        'graph.facebook.com/*/111?*' => Http::response(['id' => '111', 'instagram_business_account' => ['id' => '17841400111', 'username' => 'ukph.books']]),
        'graph.facebook.com/*/5550001*' => Http::response(['name' => 'Zara Khan', 'username' => 'zara.reads']),
    ]);

    $connection = MetaConnection::query()->create(['fb_user_id' => '42', 'fb_user_name' => 'Rabeet', 'user_token' => 'long-token']);
    app(ConnectFacebook::class)->syncPages($connection);

    expect($this->page->refresh()->instagramHandle())->toBe('@ukph.books')
        ->and($event->refresh()->status)->toBe(InboundStatus::Done)
        ->and(Lead::query()->forBrand($this->brand)->sole()->source_type)->toBe(LeadSource::Instagram);
});

it('reads the changes shape of Meta dashboard tests', function (): void {
    instagramWebhook(['object' => 'instagram', 'entry' => [['id' => '17841400111', 'time' => time(), 'changes' => [[
        'field' => 'messages',
        'value' => ['sender' => ['id' => '5550001'], 'recipient' => ['id' => '17841400111'], 'timestamp' => (string) time(), 'message' => ['mid' => 'ig.test', 'text' => 'random_text']],
    ]]]]])->assertOk();

    $message = Message::query()->sole();

    expect(InboundEvent::query()->sole()->source)->toBe(ProcessMetaEvent::INSTAGRAM)
        ->and($message->body)->toBe('random_text')
        ->and($message->created_at?->isToday())->toBeTrue();
});

it('adds a reply written in the Instagram app, but not the echo of a reply sent from here', function (): void {
    instagramWebhook(instagramDm(['mid' => 'ig.1', 'text' => 'Hi']))->assertOk();
    $conversation = Conversation::query()->acrossBrands()->sole();

    // Written on Instagram itself: it comes back as an echo from the account to the person.
    instagramWebhook(instagramDm(['mid' => 'ig.echo.1', 'text' => 'Hello Zara, yes we do!', 'is_echo' => true], from: '17841400111', to: '5550001'))->assertOk();

    $echo = Message::query()->where('external_id', 'ig.echo.1')->sole();

    expect($echo->direction)->toBe('out')
        ->and($echo->isFromFacebook())->toBeTrue()
        ->and($conversation->refresh()->last_message_preview)->toBe('You: Hello Zara, yes we do!');

    // Sent from LiquifyCRM a moment ago, and its echo arrives before Meta's answer gave it an id.
    Message::query()->create(['conversation_id' => $conversation->getKey(), 'direction' => 'out', 'body' => 'Our prices are on the way', 'status' => 'sent', 'sent_by' => admin()->getKey()]);
    instagramWebhook(instagramDm(['mid' => 'ig.echo.2', 'text' => 'Our prices are on the way', 'is_echo' => true], from: '17841400111', to: '5550001'))->assertOk();

    expect(Message::query()->where('body', 'Our prices are on the way')->count())->toBe(1);
});

it('sends a reply on Instagram through the Page, within its length', function (): void {
    instagramWebhook(instagramDm(['mid' => 'ig.1', 'text' => 'Hi']))->assertOk();
    $conversation = Conversation::query()->acrossBrands()->sole();
    inBrand($this->brand);

    Livewire::actingAs(admin())
        ->test(ConversationThread::class, ['conversationId' => $conversation->getKey()])
        ->assertSee('Instagram · @ukph.books')
        ->assertSee('Instagram takes up to 1,000 characters')
        ->set('reply', str_repeat('Long words ', 100))
        ->call('send')
        ->assertHasErrors('reply')
        ->set('reply', 'Yes, we publish poetry.')
        ->call('send')
        ->assertHasNoErrors();

    $sent = Message::query()->where('direction', 'out')->sole();

    expect($sent->status)->toBe(MessageStatus::Sent)
        ->and($sent->external_id)->toBe('ig.out.1');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/111/messages')
        && $request['recipient']['id'] === '5550001'
        && $request['message']['text'] === 'Yes, we publish poetry.'
        && ! isset($request['messaging_type'])
        && $request->hasHeader('Authorization', 'Bearer page-token'));

    $this->assertDatabaseHas('activity_log', ['event' => 'messages.reply_sent', 'description' => 'Instagram reply sent']);

    // Seen on Instagram: the reply is read.
    instagramWebhook(['object' => 'instagram', 'entry' => [['id' => '17841400111', 'time' => time(), 'messaging' => [[
        'sender' => ['id' => '5550001'], 'recipient' => ['id' => '17841400111'], 'timestamp' => now()->addSecond()->getTimestampMs(), 'read' => ['mid' => 'ig.out.1'],
    ]]]]])->assertOk();

    expect($sent->refresh()->status)->toBe(MessageStatus::Read);
});

it('removes the words of a message the person deleted', function (): void {
    instagramWebhook(instagramDm(['mid' => 'ig.1', 'text' => 'My phone is 07700 900123']))->assertOk();
    instagramWebhook(instagramDm(['mid' => 'ig.1', 'is_deleted' => true]))->assertOk();

    $message = Message::query()->sole();

    expect(InboundEvent::query()->count())->toBe(2)
        ->and($message->body)->toBe('[Deleted by the sender]')
        ->and($message->raw)->toBeNull()
        ->and(Conversation::query()->acrossBrands()->sole()->last_message_preview)->toBe('[Deleted by the sender]');
});

it('shows shared posts, story replies and disappearing photos in words', function (): void {
    instagramWebhook(instagramDm(['mid' => 'ig.1', 'attachments' => [['type' => 'ig_reel', 'payload' => ['url' => 'https://lookaside.example.com/reel.mp4', 'title' => 'Our launch party']]]]))->assertOk();
    instagramWebhook(instagramDm(['mid' => 'ig.2', 'text' => 'Love this!', 'reply_to' => ['story' => ['url' => 'https://lookaside.example.com/story.jpg', 'id' => '99']]]))->assertOk();
    instagramWebhook(instagramDm(['mid' => 'ig.3', 'attachments' => [['type' => 'ephemeral']]]))->assertOk();

    $reel = Message::query()->where('external_id', 'ig.1')->sole();
    $story = Message::query()->where('external_id', 'ig.2')->sole();

    expect($reel->attachments())->toBe([['type' => 'link', 'url' => 'https://lookaside.example.com/reel.mp4', 'name' => 'Our launch party']])
        ->and($reel->isOnlyAttachments())->toBeTrue()
        ->and($story->attachments())->toBe([['type' => 'link', 'url' => 'https://lookaside.example.com/story.jpg', 'name' => 'Replied to your story']])
        ->and(Message::query()->where('external_id', 'ig.3')->value('body'))->toContain('disappearing photo or video');
});

it('lists Instagram chats in the Inbox with their own channel', function (): void {
    instagramWebhook(instagramDm(['mid' => 'ig.1', 'text' => 'Hi from Instagram']))->assertOk();

    $messenger = Conversation::query()->create([
        'brand_id' => $this->brand->getKey(), 'contact_external_id' => '777', 'contact_name' => 'Amy Wong', 'meta_source_id' => $this->page->getKey(),
        'lead_id' => Lead::factory()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Amy Wong'])->getKey(),
        'last_inbound_at' => now(), 'last_message_at' => now()->subMinute(), 'last_message_preview' => 'Hi from Messenger',
    ]);
    $instagram = Conversation::query()->acrossBrands()->where('channel', 'instagram')->sole();
    inBrand($this->brand);

    Livewire::actingAs(admin())
        ->test(Inbox::class, ['brand' => $this->brand])
        ->assertSee('Every channel')
        ->assertSee('Hi from Instagram')
        ->assertSee('Hi from Messenger')
        ->set('channel', 'instagram')
        ->assertSee('Hi from Instagram')
        ->assertDontSee('Hi from Messenger')
        ->set('channel', '')
        ->set('search', '@zara.re')
        ->assertSee('Hi from Instagram')
        ->assertDontSee('Hi from Messenger')
        ->set('search', '')
        ->call('open', $instagram->getKey())
        ->assertSee('Wrote to')
        ->assertSee('https://www.instagram.com/zara.reads/')
        ->assertSee('Name and picture from Instagram');

    Livewire::actingAs(admin())
        ->test(LeadPanel::class, ['leadId' => $instagram->lead_id, 'page' => true])
        ->assertSee('Instagram account')
        ->assertSee('@ukph.books');

    expect($messenger->channel)->toBe(Conversation::CHANNEL_MESSENGER);
});
