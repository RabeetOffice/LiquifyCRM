<?php

declare(strict_types=1);

use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Leads\Actions\AssignLead;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Models\Lead;
use App\Modules\Meta\Jobs\ProcessMetaEvent;
use App\Modules\Meta\Livewire\Admin\IntegrationsPage;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use App\Modules\Meta\Models\MetaConnection;
use App\Modules\Meta\Models\MetaSource;
use App\Modules\Sites\Enums\InboundStatus;
use App\Modules\Sites\Models\InboundEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Week 4: the Meta webhook (verify token, X-Hub-Signature-256), Lead Ads
 * leads fetched from the Graph API, and Messenger messages joined by PSID.
 */

beforeEach(function (): void {
    Notification::fake();
    config(['liquify.meta.app_id' => '1234567890', 'liquify.meta.app_secret' => 'app-secret', 'liquify.meta.verify_token' => 'verify-me']);

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->page = MetaSource::query()->create(['brand_id' => $this->brand->getKey(), 'external_id' => '111', 'name' => 'UKPH Page', 'access_token' => 'page-token'])->refresh();
});

/**
 * Post a webhook the way Meta does, signed with the app secret.
 *
 * @param  array<string, mixed>  $payload
 */
function metaWebhook(array $payload, string $secret = 'app-secret')
{
    $body = (string) json_encode($payload);

    return test()->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

function leadgenPayload(string $leadgenId = '9001', string $pageId = '111'): array
{
    return ['object' => 'page', 'entry' => [['id' => $pageId, 'time' => time(), 'changes' => [[
        'field' => 'leadgen',
        'value' => ['leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => '555', 'created_time' => time()],
    ]]]]];
}

function messagePayload(string $text, string $mid, string $psid = '777', string $pageId = '111', ?int $at = null): array
{
    return ['object' => 'page', 'entry' => [['id' => $pageId, 'time' => time(), 'messaging' => [[
        'sender' => ['id' => $psid],
        'recipient' => ['id' => $pageId],
        'timestamp' => ($at ?? time()) * 1000,
        'message' => ['mid' => $mid, 'text' => $text],
    ]]]]];
}

function fakeGraph(): void
{
    Http::fake([
        'graph.facebook.com/*/9001*' => Http::response(['id' => '9001', 'created_time' => now()->subMinute()->toIso8601String(), 'form_id' => '555', 'campaign_name' => 'Autumn', 'field_data' => [
            ['name' => 'full_name', 'values' => ['Sarah Lee']],
            ['name' => 'email', 'values' => ['sarah@example.com']],
            ['name' => 'phone_number', 'values' => ['+447700900123']],
            ['name' => 'which_service?', 'values' => ['Publishing']],
        ]]),
        'graph.facebook.com/*/555*' => Http::response(['name' => 'Free consultation']),
        'graph.facebook.com/*/777*' => Http::response(['first_name' => 'Amy', 'last_name' => 'Wong']),
        'graph.facebook.com/*/111/messages' => Http::response(['recipient_id' => '777', 'message_id' => 'mid.out.1']),
    ]);
}

it('answers the verification challenge only with the right verify token', function (): void {
    $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=4242')->assertOk()->assertSee('4242');
    $this->get('/webhooks/meta?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=4242')->assertForbidden();
});

it('refuses a webhook that is not signed with the app secret', function (): void {
    metaWebhook(leadgenPayload(), secret: 'someone-else')->assertStatus(401);
    $this->postJson('/webhooks/meta', leadgenPayload())->assertStatus(401);

    expect(InboundEvent::query()->count())->toBe(0);
});

it('only stores the event and answers 200; the queue does the rest', function (): void {
    Queue::fake();

    metaWebhook(leadgenPayload())->assertOk()->assertSee('EVENT_RECEIVED');
    metaWebhook(leadgenPayload())->assertOk();

    expect(InboundEvent::query()->count())->toBe(1);
    Queue::assertPushed(ProcessMetaEvent::class, 1);
});

it('fetches a Lead Ads lead from the Graph API into the brand of its Page', function (): void {
    fakeGraph();

    metaWebhook(leadgenPayload())->assertOk();

    $lead = Lead::query()->forBrand($this->brand)->sole();

    expect($lead->source_type)->toBe(LeadSource::Facebook)
        ->and($lead->external_id)->toBe('9001')
        ->and($lead->name)->toBe('Sarah Lee')
        ->and($lead->email)->toBe('sarah@example.com')
        ->and($lead->phone)->toBe('+447700900123')
        ->and($lead->assigned_to)->toBeNull()
        ->and($lead->fields)->toMatchArray(['facebook_page' => 'UKPH Page', 'form' => 'Free consultation', 'campaign' => 'Autumn', 'which_service' => 'Publishing'])
        ->and(InboundEvent::query()->sole()->status)->toBe(InboundStatus::Done)
        ->and($this->page->refresh()->last_event_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v26.0/9001') && $request->hasHeader('Authorization', 'Bearer page-token') && ! str_contains($request->url(), 'page-token'));
});

it('keeps events of an unmapped Page and processes them once it is mapped', function (): void {
    fakeGraph();
    $this->page->update(['brand_id' => null]);

    metaWebhook(leadgenPayload())->assertOk();

    $event = InboundEvent::query()->sole();

    expect($event->status)->toBe(InboundStatus::Failed)
        ->and($event->error)->toContain('not mapped to a brand')
        ->and(Lead::query()->acrossBrands()->count())->toBe(0);

    MetaConnection::query()->firstOrCreate(['fb_user_id' => '42'], ['fb_user_name' => 'Rabeet', 'user_token' => 'long-token']);

    Livewire::actingAs(admin())
        ->test(IntegrationsPage::class)
        ->assertSee('UKPH Page')
        ->assertSee('Waiting for a brand')
        ->set('tab', 'received')
        ->assertSee('not mapped to a brand')
        ->set('tab', 'facebook')
        ->call('mapPage', $this->page->getKey(), (string) $this->brand->getKey());

    expect($event->refresh()->status)->toBe(InboundStatus::Done)
        ->and(Lead::query()->forBrand($this->brand)->count())->toBe(1);
});

it('turns the first Messenger message into a lead and a conversation', function (): void {
    fakeGraph();

    metaWebhook(messagePayload('Hi, how much is publishing?', 'mid.1'))->assertOk();

    $conversation = Conversation::query()->acrossBrands()->sole();
    $lead = Lead::query()->forBrand($this->brand)->sole();

    expect($conversation->brand_id)->toBe($this->brand->getKey())
        ->and($conversation->contact_name)->toBe('Amy Wong')
        ->and($conversation->contact_external_id)->toBe('777')
        ->and($conversation->lead_id)->toBe($lead->getKey())
        ->and($conversation->unread_count)->toBe(1)
        ->and($conversation->canReply())->toBeTrue()
        ->and($lead->source_type)->toBe(LeadSource::Messenger)
        ->and($lead->name)->toBe('Amy Wong')
        ->and($lead->message)->toBe('Hi, how much is publishing?')
        ->and(Message::query()->sole()->direction)->toBe('in');
});

it('adds later messages to the same conversation and tells the owner', function (): void {
    fakeGraph();
    $agent = agentOf(department($this->brand));

    metaWebhook(messagePayload('Hi', 'mid.1'))->assertOk();

    inBrand($this->brand);
    $lead = Lead::query()->sole();
    app(AssignLead::class)->handle($lead, $agent, admin());

    metaWebhook(messagePayload('Are you there?', 'mid.2'))->assertOk();
    metaWebhook(messagePayload('Are you there?', 'mid.2'))->assertOk();

    expect(Conversation::query()->count())->toBe(1)
        ->and(Message::query()->count())->toBe(2)
        ->and(Lead::query()->count())->toBe(1)
        ->and(Conversation::query()->sole()->unread_count)->toBe(2);

    Notification::assertSentTo($agent, fn (AppNotification $notification): bool => str_contains($notification->title, 'New message from Amy Wong'));
});

it('ignores echoes of the Page own messages', function (): void {
    fakeGraph();

    $payload = messagePayload('Thanks!', 'mid.echo', psid: '111');
    $payload['entry'][0]['messaging'][0]['message']['is_echo'] = true;

    metaWebhook($payload)->assertOk();

    expect(Conversation::query()->acrossBrands()->count())->toBe(0)
        ->and(InboundEvent::query()->sole()->status)->toBe(InboundStatus::Done);
});
