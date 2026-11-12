<?php

declare(strict_types=1);

use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
 * The chat served from the brand's own website through liquify-chat.php (user request, 12 Nov 2026: hide LiquifyCRM's
 * address). A call the file signed tells the visitor's real address and gets every address back through the file; an
 * unsigned, old or foreign call changes nothing.
 */

beforeEach(function (): void {
    Notification::fake();
    Cache::flush();
    $this->brand = brand(['name' => 'Ireland Publishing House', 'slug' => 'iph']);
    $this->front = department($this->brand, ['name' => 'Front Sales']);
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'Ireland site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['irelandpublishinghouse.ie'],
    ]);
    $this->secret = $this->widget->proxySecret();
    inBrand($this->brand);
});

/** @return array<string, string> the headers liquify-chat.php adds, signed (or not) as asked */
function proxyHeaders(object $test, string $ip = '81.2.69.142', string $base = 'https://www.irelandpublishinghouse.ie/liquify-chat.php', ?int $time = null, ?string $secret = null): array
{
    $time ??= time();
    $key = $test->widget->widget_key;

    return [
        'X-Liquify-Proxy-Key' => $key, 'X-Liquify-Proxy-Ip' => $ip, 'X-Liquify-Proxy-Base' => $base, 'X-Liquify-Proxy-Ts' => (string) $time,
        'X-Liquify-Proxy-Sig' => hash_hmac('sha256', $key.'|'.$ip.'|'.$base.'|'.$time, $secret ?? $test->secret),
    ];
}

function proxiedInit(object $test, array $proxy): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->postJson('/chat/api/init', [], ['X-Chat-Key' => $test->widget->widget_key, ...$proxy]);
}

it('believes a signed call: the visitor’s own address, and every address handed back goes through the website’s file', function (): void {
    $proxy = proxyHeaders($this);

    $this->getJson('/chat/api/config?key='.$this->widget->widget_key.'&o='.urlencode('https://www.irelandpublishinghouse.ie'), $proxy)->assertOk()
        ->assertJsonPath('frame', 'https://www.irelandpublishinghouse.ie/liquify-chat.php?p=frame/'.$this->widget->widget_key);

    proxiedInit($this, $proxy)->assertOk();
    expect(ChatVisitor::query()->sole()->ip)->toBe('81.2.69.142');

    $frame = $this->get('/chat/frame/'.$this->widget->widget_key.'?o='.urlencode('https://www.irelandpublishinghouse.ie'), $proxy)->assertOk()->getContent();
    expect($frame)->toContain('data-api="https://www.irelandpublishinghouse.ie/liquify-chat.php?p=api"')->not->toContain(url('/chat/api'));
});

it('ignores a call that is unsigned, wrongly signed, too old or from a file on another website', function (array $proxy): void {
    $this->getJson('/chat/api/config?key='.$this->widget->widget_key.'&o='.urlencode('https://www.irelandpublishinghouse.ie'), $proxy)->assertOk()
        ->assertJsonPath('frame', url('/chat/frame/'.$this->widget->widget_key));

    proxiedInit($this, $proxy)->assertOk();
    expect(ChatVisitor::query()->sole()->ip)->toBe('10.0.0.5');
})->with([
    'unsigned' => fn (): array => ['X-Liquify-Proxy-Ip' => '81.2.69.142'],
    'wrong secret' => fn (): array => proxyHeaders(test(), secret: 'not-the-secret'),
    'too old' => fn (): array => proxyHeaders(test(), time: time() - 600),
    'another website' => fn (): array => proxyHeaders(test(), base: 'https://evil.example/liquify-chat.php'),
    'not a php file' => fn (): array => proxyHeaders(test(), base: 'https://www.irelandpublishinghouse.ie/x.js'),
    'odd characters' => fn (): array => proxyHeaders(test(), base: 'https://www.irelandpublishinghouse.ie/a"b.php'),
]);

it('limits each visitor by their own address, not the website server that passes all of them on', function (): void {
    foreach (range(1, 12) as $n) {
        proxiedInit($this, proxyHeaders($this, ip: '81.2.69.'.$n))->assertOk();
    }

    expect(ChatVisitor::query()->count())->toBe(12);
});

it('gives liquify-chat.php only to people who may change the chat box, with the key, the secret and LiquifyCRM’s address in it', function (): void {
    $url = route('brand.live-chat.proxy-file', ['brand' => 'iph', 'widget' => $this->widget->getKey()]);

    $file = $this->actingAs(managerOf($this->front))->get($url)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="liquify-chat.php"')->getContent();
    expect($file)->toContain("const LIQUIFY_CHAT_KEY     = '".$this->widget->widget_key."'")
        ->toContain("const LIQUIFY_CHAT_SECRET  = '".$this->secret."'")
        ->toContain("const LIQUIFY_CHAT_CRM     = '".rtrim(url('/'), '/')."'")
        ->and($this->widget->refresh()->proxySecret())->toBe($this->secret);

    auth()->logout();
    $this->actingAs(agentOf($this->front))->get($url)->assertForbidden();
    auth()->logout();
    $this->actingAs(managerOf(department(brand(['slug' => 'other']))))->get($url)->assertNotFound();

    // The secret never leaves in the widget's own data.
    expect($this->widget->refresh()->toArray())->not->toHaveKey('proxy_secret');
});

it('ships a file that is plain, valid PHP for the brand websites', function (): void {
    $code = $this->widget->proxyFile();

    expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class)
        ->and($code)->toContain("'~^(widget\.js|frame/wk_[A-Za-z0-9]{24}|api/(config|init|messages|realtime|typing|ping|upload|details|rate)|files/[0-9]{1,12})$~'");
});
