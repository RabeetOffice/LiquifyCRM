<?php

declare(strict_types=1);

use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\Realtime;
use App\Modules\Meta\Livewire\App\ConversationThread;
use App\Modules\Meta\Models\Conversation;
use App\Modules\Meta\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Live chat, phase 4 (4 Nov 2026): instant signals through Ably's free tier. The server sends one tiny "changed" signal
 * and signs tokens that let a browser listen to its own channel; nothing else leaves the server, and everything works
 * as before when there is no key or Ably does not answer.
 */

const ABLY_KEY = 'AbC123.xYz789:s3cr3t-key-value';

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();

    $this->brand = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dpp']);
    $this->department = department($this->brand, ['name' => 'Front Sales']);
    $this->manager = managerOf($this->department, ['name' => 'Farah Front']);
    $this->widget = ChatWidget::query()->create(['brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['dublinpublishingpress.ie']]);

    inBrand($this->brand);
});

/** Ably answering as it does: a published message is 201, a token request gives a token. */
function fakeAbly(): void
{
    Http::fake([
        '*/channels/*/messages' => Http::response(['channel' => 'x', 'messageId' => '1'], 201),
        '*/keys/*/requestToken' => Http::response(['token' => 'tok-'.uniqid(), 'keyName' => 'AbC123.xYz789', 'expires' => 1], 200),
    ]);
}

/** A visitor writes in the chat box. */
function rtVisitor(object $test): array
{
    $init = $test->postJson('/chat/api/init', [], ['X-Chat-Key' => $test->widget->widget_key])->assertOk();
    $headers = ['X-Chat-Key' => $test->widget->widget_key, 'X-Chat-Token' => $init->json('token'), 'X-Chat-Sig' => $init->json('sig')];
    $test->postJson('/chat/api/messages', ['body' => 'Do you publish memoirs?'], $headers)->assertOk();

    return [$headers, Conversation::query()->latest('id')->firstOrFail()];
}

it('does nothing at all without a key, and the chat box and the pages are told so', function (): void {
    config(['liquify.realtime.ably_key' => null]);
    Http::fake();

    expect(Realtime::enabled())->toBeFalse();
    [$headers] = rtVisitor($this);

    $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertJsonPath('config.realtime', false);
    $this->postJson('/chat/api/realtime', [], $headers)->assertOk()->assertExactJson(['enabled' => false]);
    $this->actingAs($this->manager)->getJson('/b/dpp/realtime')->assertNoContent();
    $this->get('/b/dpp/inbox')->assertOk()->assertDontSee('data-realtime', false);

    Http::assertNothingSent();
});

it('sends the brand\'s open pages one signal when a visitor writes, with no words in it', function (): void {
    config(['liquify.realtime.ably_key' => ABLY_KEY]);
    fakeAbly();

    rtVisitor($this);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/channels/lq-b-'.$this->brand->getKey().'/messages')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('AbC123.xYz789:s3cr3t-key-value'))
            && $request->data() === ['name' => 'changed', 'data' => '1']
            && ! str_contains($request->body(), 'memoirs');
    });
});

it('tells the visitor\'s chat box at once when the team answers, closes the chat or types', function (): void {
    [$headers, $chat] = rtVisitor($this);
    config(['liquify.realtime.ably_key' => ABLY_KEY]);
    fakeAbly();

    $visitorChannel = Realtime::visitorChannel(ChatVisitor::query()->sole()->visitor_token);
    expect($visitorChannel)->toStartWith('lq-v-')->and($visitorChannel)->not->toContain(ChatVisitor::query()->sole()->visitor_token);

    $heard = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/channels/'.$visitorChannel.'/messages'))->count();

    // An answer.
    $thread = Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true]);
    $thread->set('reply', 'Yes we do')->call('send');
    expect($heard())->toBe(1);

    // A note for the team is nobody else's business.
    $thread->set('mode', 'note')->set('reply', 'Looks serious')->call('send');
    expect($heard())->toBe(1);

    // The team starts typing (once, not on every keystroke), then closes the chat.
    $this->actingAs($this->manager)->postJson('/b/dpp/conversations/'.$chat->getKey().'/typing')->assertOk();
    $this->actingAs($this->manager)->postJson('/b/dpp/conversations/'.$chat->getKey().'/typing')->assertOk();
    expect($heard())->toBe(2);
    $thread->call('close');
    expect($heard())->toBe(3);
    $thread->call('reopen');
    expect($heard())->toBe(4);

    // None of it carried the words.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'Yes we do') || str_contains($request->body(), 'Looks serious'));
});

it('signs a token for one channel and listening only, the way Ably\'s spec says, and keeps it for reuse', function (): void {
    config(['liquify.realtime.ably_key' => ABLY_KEY]);
    fakeAbly();
    [$headers] = rtVisitor($this);

    $answer = $this->postJson('/chat/api/realtime', [], $headers)->assertOk()->assertJsonPath('enabled', true);
    $channel = Realtime::visitorChannel(ChatVisitor::query()->sole()->visitor_token);

    expect($answer->json('url'))->toStartWith('https://main.realtime.ably.net/event-stream?channels='.$channel.'&v=1.2&accessToken=tok-')
        ->and($answer->json('refresh_in'))->toBe(2700);

    $sent = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/keys/AbC123.xYz789/requestToken'))->first()[0];
    $body = $sent->data();

    expect($body['keyName'])->toBe('AbC123.xYz789')
        ->and($body['ttl'])->toBe(3600000)
        ->and($body['capability'])->toBe('{"'.$channel.'":["subscribe"]}')
        ->and($body)->not->toHaveKey('clientId');

    // The signature: keyName, ttl, capability, clientId (empty), timestamp and nonce, each and a newline, HMAC-SHA-256 with the secret.
    $text = $body['keyName']."\n".$body['ttl']."\n".$body['capability']."\n\n".$body['timestamp']."\n".$body['nonce']."\n";
    expect($body['mac'])->toBe(base64_encode(hash_hmac('sha256', $text, 's3cr3t-key-value', true)))
        ->and(strlen($body['nonce']))->toBeGreaterThanOrEqual(16)
        ->and(abs($body['timestamp'] - (int) (microtime(true) * 1000)))->toBeLessThan(5000);
    // The secret itself is never sent in the request.
    expect($sent->body())->not->toContain('s3cr3t-key-value');

    // A second ask for the same channel uses the token already made.
    $this->postJson('/chat/api/realtime', [], $headers)->assertOk();
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/requestToken'))->count())->toBe(1);

    // A forged token gets nothing.
    $this->postJson('/chat/api/realtime', [], ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $headers['X-Chat-Token'], 'X-Chat-Sig' => str_repeat('0', 40)])->assertUnauthorized();
});

it('gives the team a token for their own brand\'s channel only, and nobody else any', function (): void {
    config(['liquify.realtime.ably_key' => ABLY_KEY]);
    fakeAbly();

    $answer = $this->actingAs($this->manager)->getJson('/b/dpp/realtime')->assertOk();
    expect($answer->json('url'))->toContain('channels=lq-b-'.$this->brand->getKey().'&v=1.2');

    $sent = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/requestToken'))->first()[0]->data();
    expect($sent['capability'])->toBe('{"lq-b-'.$this->brand->getKey().'":["subscribe"]}');

    // Somebody who is not in the brand is turned away like from any page of it; a visitor of another site cannot ask either.
    $this->flushSession();
    $outsider = agentOf(department(brand(['slug' => 'ukph']), ['name' => 'Sales']));
    $this->actingAs($outsider)->getJson('/b/dpp/realtime')->assertNotFound();
    $this->flushSession();
    $this->getJson('/b/dpp/realtime')->assertNotFound();

    // The pages of the brand carry the address of the token endpoint, and the chat box learns it may listen.
    $this->flushSession();
    $this->actingAs($this->manager)->get('/b/dpp/inbox')->assertOk()->assertSee('data-realtime="'.url('/b/dpp/realtime').'"', false);
    $this->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertJsonPath('config.realtime', true);
});

it('keeps working when Ably is away: nothing is lost and nobody sees an error', function (): void {
    config(['liquify.realtime.ably_key' => ABLY_KEY]);
    Http::fake([
        '*/channels/*/messages' => Http::response('Service Unavailable', 503),
        '*/keys/*/requestToken' => fn () => throw new RuntimeException('connection refused'),
    ]);

    // The visitor's message is stored whatever Ably does.
    [$headers, $chat] = rtVisitor($this);
    expect($chat->unread_count)->toBe(1);

    // The chat box and the pages are simply told there is nothing to listen to; they poll.
    $this->postJson('/chat/api/realtime', [], $headers)->assertOk()->assertExactJson(['enabled' => false]);
    $this->actingAs($this->manager)->getJson('/b/dpp/realtime')->assertNoContent();

    // And the team's answer goes through too.
    Livewire::actingAs($this->manager)->test(ConversationThread::class, ['conversationId' => $chat->getKey(), 'inbox' => true])->set('reply', 'Hello')->call('send')->assertHasNoErrors();
    expect(Message::query()->where('kind', 'text')->where('direction', 'out')->count())->toBe(1);
});
