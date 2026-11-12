<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\Chat\Http\Controllers\WidgetController;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Core\Enums\GlobalRole;
use App\Modules\Meta\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Live chat, phase 8 (hardening, 12 Nov 2026): what a script cannot do to the chat box's public calls. A token is free to get,
 * so every limit per token also has a ceiling per address; new visitors and new website visits are capped per address; a
 * website's origin is only accepted, and echoed, in its clean https form.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();

    $this->brand = brand(['name' => 'Dublin Publishing Press', 'slug' => 'dpp']);
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'Dublin site', 'widget_key' => ChatWidget::newKey(),
        'allowed_domains' => ['dublinpublishingpress.ie', 'localhost'],
    ]);

    inBrand($this->brand);
});

function hardenInit(object $test, array $headers = []): TestResponse
{
    return $test->postJson('/chat/api/init', [], ['X-Chat-Key' => $test->widget->widget_key, ...$headers]);
}

function hardenPing(object $test, string $vid, string $origin = 'https://www.dublinpublishingpress.ie'): TestResponse
{
    return $test->call('POST', '/chat/api/ping', [], [], [], ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'text/plain'], json_encode(['key' => $test->widget->widget_key, 'vid' => $vid, 'url' => 'https://www.dublinpublishingpress.ie/memoirs', 'title' => 'Memoirs']));
}

it('gives one address only a few new visitors a minute, while a visitor with a token keeps working', function (): void {
    $first = hardenInit($this)->assertOk();

    for ($i = 1; $i < WidgetController::NEW_VISITORS_PER_MINUTE; $i++) {
        hardenInit($this)->assertOk();
    }

    hardenInit($this)->assertStatus(429);
    expect(ChatVisitor::query()->count())->toBe(WidgetController::NEW_VISITORS_PER_MINUTE);

    // The first visitor comes back with the token they hold: not a new visitor, so not refused.
    hardenInit($this, ['X-Chat-Token' => $first->json('token'), 'X-Chat-Sig' => $first->json('sig')])->assertOk()->assertJson(['token' => $first->json('token')]);
    expect(ChatVisitor::query()->count())->toBe(WidgetController::NEW_VISITORS_PER_MINUTE);
});

it('stops a script that sends from many tokens at the ceiling of its address', function (): void {
    $sent = 0;
    $refused = false;

    // Three tokens of twenty messages each fill the address's sixty; the next message is refused whatever token it carries.
    foreach (range(1, 4) as $round) {
        $creds = hardenInit($this)->assertOk();
        $headers = ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')];

        foreach (range(1, 20) as $n) {
            $response = $this->postJson('/chat/api/messages', ['body' => 'Hello '.$round.'-'.$n], $headers);

            if ($response->status() === 429) {
                $refused = true;
                break 2;
            }

            $response->assertOk();
            $sent++;
        }
    }

    expect($refused)->toBeTrue()->and($sent)->toBe(60)
        ->and(Message::query()->count())->toBe(60);
});

it('notes only a few new browsers a minute from one address, and refuses a browser that pings like a script', function (): void {
    for ($i = 0; $i < WidgetController::NEW_SESSIONS_PER_MINUTE + 5; $i++) {
        hardenPing($this, (string) Str::uuid())->assertOk();
    }

    expect(ChatSession::query()->count())->toBe(WidgetController::NEW_SESSIONS_PER_MINUTE);

    Cache::flush();
    $vid = (string) Str::uuid();

    for ($i = 0; $i < 20; $i++) {
        hardenPing($this, $vid)->assertOk();
    }

    hardenPing($this, $vid)->assertStatus(429);
});

it('accepts a website only on https and echoes its clean origin, never what was sent', function (): void {
    $config = '/chat/api/config?key='.$this->widget->widget_key;

    // Plain http can be changed on the way: no chat box there.
    $this->getJson($config, ['Origin' => 'http://www.dublinpublishingpress.ie'])->assertNotFound()->assertHeaderMissing('Access-Control-Allow-Origin');
    // A copy of the website on this computer may use http and a port.
    $this->getJson($config, ['Origin' => 'http://localhost:9000'])->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:9000');
    // A name that only ends like the website, or carries a login, is not the website.
    $this->getJson($config, ['Origin' => 'https://evildublinpublishingpress.ie'])->assertNotFound();
    $this->getJson($config, ['Origin' => 'https://user:pass@www.dublinpublishingpress.ie'])->assertNotFound();

    // The frame takes the website from its address: a path or quotes are dropped or refused, never written into the page.
    $frame = $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('https://WWW.dublinpublishingpress.ie/memoirs?x=1'))->assertOk();
    expect($frame->getContent())->toContain('data-origin="https://www.dublinpublishingpress.ie"')->not->toContain('/memoirs');

    $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('https://www.dublinpublishingpress.ie" onload="x'))->assertNotFound();
    $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('http://www.dublinpublishingpress.ie'))->assertNotFound();

    // Pings from plain http are not kept either.
    hardenPing($this, (string) Str::uuid(), 'http://www.dublinpublishingpress.ie')->assertNoContent();
    expect(ChatSession::query()->count())->toBe(0);
});

it('opens each chat screen only for the roles that should have it (role crawl)', function (): void {
    $front = department($this->brand, ['name' => 'Front Sales']);
    $upsell = department($this->brand, ['name' => 'Upsell', 'kind' => 'upsell']);
    $people = [
        'super' => superAdmin(), 'admin' => admin(), 'manager' => managerOf($front), 'agent' => agentOf($front),
        'upsell-agent' => agentOf($upsell), 'other-manager' => managerOf(department(brand(['slug' => 'ukph']))),
        'it' => User::factory()->withTwoFactor()->create(['role' => GlobalRole::It])->refresh(),
        'web-leads' => User::factory()->create(['role' => GlobalRole::WebsiteLeads])->refresh(),
    ];
    $paths = ['live-chat/chats', 'live-chat/visitors', 'saved-replies', 'live-chat'];
    $expected = [
        'super' => [200, 200, 200, 200],
        'admin' => [200, 200, 200, 200],
        'manager' => [200, 200, 200, 200],
        // Agents answer chats but do not set the chat boxes up.
        'agent' => [200, 200, 200, 403],
        // Website visitors are front sales' business, not Upsell's.
        'upsell-agent' => [200, 403, 200, 403],
        // Strangers to the brand, IT and Website leads people are never in a brand.
        'other-manager' => [404, 404, 404, 404],
        'it' => [404, 404, 404, 404],
        'web-leads' => [404, 404, 404, 404],
    ];

    foreach ($people as $name => $person) {
        foreach ($paths as $i => $path) {
            auth()->logout();
            expect($this->actingAs($person)->get('/b/dpp/'.$path)->status())->toBe($expected[$name][$i], $name.' on '.$path);
        }
    }

    // An Upsell-only Agent sees no visitor's chat, and the tab offers them no Chats item.
    $creds = hardenInit($this)->assertOk();
    $this->postJson('/chat/api/messages', ['body' => 'Do you publish memoirs?'], ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')])->assertOk();
    auth()->logout();
    $this->actingAs($people['upsell-agent'])->get('/b/dpp/live-chat/chats')->assertOk()->assertDontSee('Do you publish memoirs?')->assertDontSee('lq-side-sublink" href="'.route('brand.live-chat.chats', ['brand' => 'dpp']), false);
    auth()->logout();
    $this->actingAs($people['agent'])->get('/b/dpp/live-chat/chats')->assertOk()->assertSee('Do you publish memoirs?');
});
