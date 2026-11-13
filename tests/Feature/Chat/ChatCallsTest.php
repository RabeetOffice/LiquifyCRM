<?php

declare(strict_types=1);

use App\Modules\Chat\Actions\EndCall;
use App\Modules\Chat\Livewire\App\ChatWidgets;
use App\Modules\Chat\Models\ChatCall;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\AgentPresence;
use App\Modules\Chat\Support\CallServers;
use App\Modules\Core\Notifications\AppNotification;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Voice calls from the website chat box (user request, 12 Nov 2026; docs/CALLS_PLAN.md): the visitor calls, the CRM
 * rings for everybody who works the brand's website visitors, the first to accept talks. Only the set-up of a call
 * passes through the server; the sound does not, so these tests are about who may do what and what is kept.
 */

const CALL_OFFER = "v=0\r\no=- 46117317 2 IN IP4 127.0.0.1\r\ns=-\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\nc=IN IP4 203.0.113.9\r\na=sendrecv\r\n";
const CALL_ANSWER = "v=0\r\no=- 99117317 2 IN IP4 127.0.0.1\r\ns=-\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\nc=IN IP4 198.51.100.7\r\na=sendrecv\r\n";

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    config(['liquify.chat.turn' => ['url' => 'relay.example.com:3478', 'username' => 'relay-user', 'password' => 'relay-secret']]);

    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->admin = admin();
    $this->manager = managerOf($this->front);
    $this->agent = agentOf($this->front);
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'UK site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['ukpublishinghouse.co.uk'],
    ]);
    inBrand($this->brand);

    // Somebody of the team has a page of the brand open.
    AgentPresence::seen((int) $this->manager->getKey(), (int) $this->brand->getKey());
    AgentPresence::forget((int) $this->brand->getKey());
});

function callHeaders(object $test): array
{
    $creds = $test->postJson('/chat/api/init', [], ['X-Chat-Key' => $test->widget->widget_key])->assertOk();

    return ['X-Chat-Key' => $test->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')];
}

function startCall(object $test, array $headers): ChatCall
{
    $id = $test->postJson('/chat/api/call', ['sdp' => CALL_OFFER], $headers)->assertOk()->json('id');

    return ChatCall::query()->findOrFail($id);
}

it('offers the phone button and hands the relay only to a visitor who starts a call', function (): void {
    $init = $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    expect($init->json('config.call'))->toBeTrue();

    // The relay's sign-in is in nothing the chat box is drawn with.
    expect($init->getContent())->not->toContain('relay-secret');
    $this->getJson('/chat/api/config?key='.$this->widget->widget_key, ['Origin' => 'https://ukpublishinghouse.co.uk'])->assertOk()->assertDontSee('relay-secret');
    $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('https://ukpublishinghouse.co.uk'))->assertOk()->assertDontSee('relay-secret')->assertSee('id="callbtn"', false);

    // No token, no servers.
    $this->postJson('/chat/api/call/ice', [], ['X-Chat-Key' => $this->widget->widget_key])->assertStatus(401);

    $servers = $this->postJson('/chat/api/call/ice', [], callHeaders($this))->assertOk()->json('iceServers');
    expect($servers[1]['urls'])->toBe(['turn:relay.example.com:3478?transport=udp', 'turn:relay.example.com:3478?transport=tcp'])
        ->and($servers[1]['credential'])->toBe('relay-secret');

    // Nothing but a host and a port is taken from the .env as a relay.
    config(['liquify.chat.turn.url' => 'javascript:alert(1)']);
    expect(CallServers::hasRelay())->toBeFalse();
});

it('rings for the team, lets the first person take the call and connects the two sides', function (): void {
    $headers = callHeaders($this);
    $call = startCall($this, $headers);
    $chat = Conversation::query()->sole();

    expect($call->status)->toBe(ChatCall::RINGING)->and($call->conversation_id)->toBe($chat->getKey());
    expect(Message::query()->where('kind', 'event')->pluck('body')->all())->toContain('The visitor is calling');
    Notification::assertSentTo($this->manager, AppNotification::class);
    Notification::assertSentTo($this->agent, AppNotification::class);

    // A second call in the same chat is refused while this one rings.
    $this->postJson('/chat/api/call', ['sdp' => CALL_OFFER], $headers)->assertStatus(422);

    // It rings for the Admin, the Manager and the Agent, on any screen.
    foreach ([$this->admin, $this->manager, $this->agent] as $person) {
        $this->actingAs($person)->getJson('/calls/incoming')->assertOk()->assertJsonPath('calls.0.id', $call->getKey())->assertJsonPath('calls.0.brand', 'UK Publishing House');
    }

    // The visitor's side still says "ringing".
    $this->getJson('/chat/api/call/'.$call->getKey(), $headers)->assertOk()->assertJson(['status' => 'ringing', 'answer' => null]);

    // The Agent is first; the Manager, a moment later, is told who has it.
    $taken = $this->actingAs($this->agent)->postJson('/calls/'.$call->getKey().'/accept')->assertOk();
    expect($taken->json('offer'))->toBe(CALL_OFFER)->and($taken->json('iceServers.1.credential'))->toBe('relay-secret');
    $this->actingAs($this->manager)->postJson('/calls/'.$call->getKey().'/accept')->assertStatus(409)->assertJson(['error' => $this->agent->name.' answered this call.']);
    $this->actingAs($this->manager)->getJson('/calls/incoming')->assertOk()->assertJsonCount(0, 'calls');

    // Only the person who took it can go on with it.
    $this->actingAs($this->manager)->postJson('/calls/'.$call->getKey().'/answer', ['sdp' => CALL_ANSWER])->assertStatus(403);
    $this->actingAs($this->manager)->getJson('/calls/'.$call->getKey())->assertStatus(403);
    $this->actingAs($this->manager)->postJson('/calls/'.$call->getKey().'/end')->assertStatus(403);

    $this->actingAs($this->agent)->postJson('/calls/'.$call->getKey().'/answer', ['sdp' => CALL_ANSWER])->assertOk();
    $this->getJson('/chat/api/call/'.$call->getKey(), $headers)->assertOk()->assertJson(['status' => 'active', 'answer' => CALL_ANSWER]);
    $this->actingAs($this->agent)->getJson('/calls/'.$call->getKey())->assertOk()->assertJson(['status' => 'active']);

    // They talk for a while, then the visitor hangs up: the addresses are gone, the chat says how long it was.
    $this->travel(125)->seconds();
    ChatCall::query()->whereKey($call->getKey())->update(['visitor_seen_at' => now(), 'agent_seen_at' => now()]);
    $this->postJson('/chat/api/call/'.$call->getKey().'/end', [], $headers)->assertOk()->assertJson(['status' => 'ended']);

    $call->refresh();
    expect($call->status)->toBe(ChatCall::ENDED)->and($call->offer)->toBeNull()->and($call->answer)->toBeNull()->and($call->ended_by)->toBe(EndCall::BY_VISITOR);
    expect(Message::query()->where('kind', 'event')->pluck('body')->all())
        ->toContain($this->agent->name.' answered the call')->toContain('Voice call ended · 2 min 5 s');
    $this->actingAs($this->agent)->getJson('/calls/'.$call->getKey())->assertOk()->assertJson(['status' => 'ended']);

    // None of these lines reach the visitor.
    expect(collect($this->getJson('/chat/api/messages', $headers)->json('messages'))->pluck('body')->implode(' '))->not->toContain('call');
});

it('keeps calls from people who do not work website visitors and from other visitors', function (): void {
    $headers = callHeaders($this);
    $call = startCall($this, $headers);

    $upsell = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']));
    $stranger = managerOf(department(brand(['slug' => 'dpp'])));

    foreach ([$upsell, $stranger] as $person) {
        $this->actingAs($person)->getJson('/calls/incoming')->assertOk()->assertJsonCount(0, 'calls');
        $this->actingAs($person)->postJson('/calls/'.$call->getKey().'/accept')->assertStatus(404);
        $this->actingAs($person)->getJson('/calls/'.$call->getKey())->assertStatus(404);
    }

    auth()->logout();
    $this->getJson('/calls/incoming')->assertStatus(401);

    // Another visitor of the same website cannot read or end this call.
    inBrand($this->brand);
    $other = callHeaders($this);
    $this->getJson('/chat/api/call/'.$call->getKey(), $other)->assertStatus(404);
    $this->postJson('/chat/api/call/'.$call->getKey().'/end', [], $other)->assertStatus(404);
    expect($call->refresh()->status)->toBe(ChatCall::RINGING);
});

it('turns a call nobody answers into a missed call and tells the team', function (): void {
    $headers = callHeaders($this);
    $call = startCall($this, $headers);

    // The visitor's chat box keeps asking; after a minute it is over.
    $this->travel(ChatCall::RING_SECONDS + 1)->seconds();
    $this->getJson('/chat/api/call/'.$call->getKey(), $headers)->assertOk()->assertJson(['status' => 'missed']);

    expect($call->refresh()->status)->toBe(ChatCall::MISSED)->and($call->offer)->toBeNull();
    expect(Message::query()->where('kind', 'event')->pluck('body')->all())->toContain('Missed call: nobody answered');
    Notification::assertSentTo($this->manager, AppNotification::class, fn (AppNotification $notice): bool => str_starts_with($notice->toArray($this->manager)['title'] ?? '', 'Missed call'));
    $this->actingAs($this->agent)->postJson('/calls/'.$call->getKey().'/accept')->assertStatus(409)->assertJson(['error' => 'The caller hung up.']);

    // The visitor can call again (somebody of the team is still here).
    AgentPresence::seen((int) $this->manager->getKey(), (int) $this->brand->getKey());
    AgentPresence::forget((int) $this->brand->getKey());
    $this->postJson('/chat/api/call', ['sdp' => CALL_OFFER], $headers)->assertOk();
});

it('ends a call when one side has gone quiet, and a ringing one whose caller closed the page', function (): void {
    $headers = callHeaders($this);
    $call = startCall($this, $headers);
    $this->actingAs($this->agent)->postJson('/calls/'.$call->getKey().'/accept')->assertOk();
    $this->actingAs($this->agent)->postJson('/calls/'.$call->getKey().'/answer', ['sdp' => CALL_ANSWER])->assertOk();

    // The visitor's tab is gone: the agent's next round finds out.
    $this->travel(ChatCall::SILENT_SECONDS + 5)->seconds();
    $this->actingAs($this->agent)->getJson('/calls/'.$call->getKey())->assertOk()->assertJson(['status' => 'ended']);
    expect($call->refresh()->ended_by)->toBe(EndCall::BY_TIMEOUT);

    // A caller who closed the page while it rang: the team's screens settle it when they next look.
    auth()->logout();
    inBrand($this->brand);
    $second = startCall($this, $headers);
    $this->travel(ChatCall::SILENT_SECONDS + 5)->seconds();
    $this->actingAs($this->manager)->getJson('/calls/incoming')->assertOk()->assertJsonCount(0, 'calls');
    expect($second->refresh()->status)->toBe(ChatCall::MISSED);
});

it('refuses a call when nobody is here, when the box has calls off, and anything that is not a call', function (): void {
    $headers = callHeaders($this);

    $this->postJson('/chat/api/call', ['sdp' => 'hello'], $headers)->assertStatus(422);
    $this->postJson('/chat/api/call', ['sdp' => "v=0\r\nm=audio".str_repeat('x', 21000)], $headers)->assertStatus(422);
    expect(ChatCall::query()->count())->toBe(0);

    // Nobody of the team has a page open.
    $this->travel(10)->minutes();
    AgentPresence::forget((int) $this->brand->getKey());
    $this->postJson('/chat/api/call', ['sdp' => CALL_OFFER], $headers)->assertStatus(422)->assertJson(['error' => 'Nobody is here to take a call right now. Please leave us a message.']);

    // The Admin switches calls off for this chat box.
    Livewire::actingAs($this->admin)->test(ChatWidgets::class, ['brand' => $this->brand])
        ->call('edit', $this->widget->getKey())->assertSet('voice', true)->set('voice', false)->call('save')->assertHasNoErrors();
    auth()->logout();
    inBrand($this->brand);

    expect($this->widget->refresh()->look('call'))->toBe('0');
    $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk()->assertJsonPath('config.call', false);
    $this->postJson('/chat/api/call/ice', [], $headers)->assertStatus(404);
    $this->postJson('/chat/api/call', ['sdp' => CALL_OFFER], $headers)->assertStatus(404);
});

it('lets the team invite the visitor to a call with a button in the chat box', function (): void {
    $headers = callHeaders($this);
    $this->postJson('/chat/api/messages', ['body' => 'Do you publish memoirs?'], $headers)->assertOk();
    $chat = Conversation::query()->sole();

    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])
        ->assertSee('Invite to a call')->call('inviteToCall')->assertHasNoErrors();

    auth()->logout();
    inBrand($this->brand);
    $last = collect($this->getJson('/chat/api/messages', $headers)->json('messages'))->last();
    expect($last['ask'])->toBe('call')->and($last['from'])->toBe('team')->and($last['body'])->toContain('voice call');

    // Somebody who works only on Upsell cannot.
    $upsell = agentOf(department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']));
    Livewire::actingAs($upsell)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])->assertForbidden();
});

it('passes calls through a new liquify-chat.php and hides the button behind an old one', function (): void {
    expect($this->widget->proxyFile())->toContain("LIQUIFY_CHAT_VERSION = '1.1'")->toContain('call/[0-9]{1,12}/end');
});

it('limits how often a visitor and an address can start calls', function (): void {
    $headers = callHeaders($this);

    foreach (range(1, 8) as $try) {
        $this->postJson('/chat/api/call/ice', [], $headers)->assertOk();
    }

    $this->postJson('/chat/api/call/ice', [], $headers)->assertStatus(429);
});
