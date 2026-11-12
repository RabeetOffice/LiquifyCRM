<?php

declare(strict_types=1);

use App\Modules\Chat\Jobs\LocateVisitor;
use App\Modules\Chat\Models\ChatSession;
use App\Modules\Chat\Models\ChatVisitor;
use App\Modules\Chat\Models\ChatWidget;
use App\Modules\Chat\Support\VisitorLocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Where a website visitor is (user request, 12 Nov 2026): flag, country, region, city, IP address, network, their time and
 * whether the address is a VPN. Only the address is sent to the lookup, never a private one, and a failure shows nothing.
 */

beforeEach(function (): void {
    Cache::flush();
    $this->brand = brand(['name' => 'UK Publishing House', 'slug' => 'ukph']);
    $this->widget = ChatWidget::query()->create([
        'brand_id' => $this->brand->getKey(), 'name' => 'UK site', 'widget_key' => ChatWidget::newKey(), 'allowed_domains' => ['ukpublishinghouse.co.uk'],
    ]);
    inBrand($this->brand);

    Http::fake([
        'free.freeipapi.com/*' => Http::response(['countryCode' => 'GB', 'regionName' => 'England', 'cityName' => 'London', 'asnOrganization' => 'Andrews & Arnold Ltd', 'timeZones' => ['Europe/London'], 'isProxy' => false]),
    ]);
});

it('places a public address once and caches it, and never asks for a private one', function (): void {
    expect(VisitorLocation::lookup('81.2.69.142'))->toMatchArray(['country' => 'GB', 'region' => 'England', 'city' => 'London', 'isp' => 'Andrews & Arnold Ltd', 'timezone' => 'Europe/London', 'is_proxy' => false])
        ->and(VisitorLocation::lookup('81.2.69.142'))->not->toBeNull()
        ->and(VisitorLocation::lookup('192.168.1.10'))->toBeNull()
        ->and(VisitorLocation::lookup('127.0.0.1'))->toBeNull();

    Http::assertSentCount(1);

    config(['liquify.chat.geo' => 'none']);
    expect(VisitorLocation::lookup('8.8.8.8'))->toBeNull();
});

it('notes where a new browser on the website is, and shows it to the team with its flag', function (): void {
    $vid = (string) Str::uuid();

    $this->withServerVariables(['REMOTE_ADDR' => '81.2.69.142'])
        ->call('POST', '/chat/api/ping', [], [], [], ['HTTP_ORIGIN' => 'https://ukpublishinghouse.co.uk', 'CONTENT_TYPE' => 'text/plain'], json_encode(['key' => $this->widget->widget_key, 'vid' => $vid, 'url' => 'https://ukpublishinghouse.co.uk/memoirs']))
        ->assertOk();

    $session = ChatSession::query()->sole();
    (new LocateVisitor('session', $session->getKey(), '81.2.69.142'))->handle();

    expect($session->refresh()->only(['ip', 'country', 'city', 'region', 'isp', 'timezone']))
        ->toBe(['ip' => '81.2.69.142', 'country' => 'GB', 'city' => 'London', 'region' => 'England', 'isp' => 'Andrews & Arnold Ltd', 'timezone' => 'Europe/London']);

    $this->actingAs(admin())->get('/b/ukph/live-chat/visitors')->assertOk()
        ->assertSee('London, England, United Kingdom')->assertSee('81.2.69.142')->assertSee('flagcdn.com/w40/gb.png', false);
});

it('shows the place, address, network and their time beside a visitor’s chat', function (): void {
    $creds = $this->withServerVariables(['REMOTE_ADDR' => '81.2.69.142'])->postJson('/chat/api/init', [], ['X-Chat-Key' => $this->widget->widget_key])->assertOk();
    $this->postJson('/chat/api/messages', ['body' => 'Hello there'], ['X-Chat-Key' => $this->widget->widget_key, 'X-Chat-Token' => $creds->json('token'), 'X-Chat-Sig' => $creds->json('sig')])->assertOk();

    $visitor = ChatVisitor::query()->sole();
    (new LocateVisitor('visitor', $visitor->getKey(), '81.2.69.142'))->handle();
    $visitor->forceFill(['is_proxy' => true])->save();

    $this->actingAs(admin())->get('/b/ukph/live-chat/chats?chat='.$visitor->conversations()->value('id'))->assertOk()
        ->assertSee('London, England, United Kingdom')->assertSee('81.2.69.142')->assertSee('Andrews &amp; Arnold Ltd', false)
        ->assertSee('VPN detected')->assertSee('Their time');
});

it('leaves a visitor that moved to another address for that address’s own lookup', function (): void {
    $visitor = ChatVisitor::query()->create(['brand_id' => $this->brand->getKey(), 'visitor_token' => (string) Str::uuid(), 'ip' => '8.8.4.4']);

    (new LocateVisitor('visitor', $visitor->getKey(), '81.2.69.142'))->handle();

    expect($visitor->refresh()->city)->toBeNull();
    Http::assertNothingSent();
});

it('catches a VPN by the computer’s own clock, by a hosting company’s address, or by the lookup', function (): void {
    // Browsec: an address in Ashburn, a computer set to Pakistan time.
    expect(VisitorLocation::vpn(false, 'Leaseweb USA, Inc.', 'US', 'Asia/Karachi'))->toBe(['vpn' => true, 'real' => 'PK'])
        ->and(VisitorLocation::vpn(false, 'Some Hosting', 'US', 'America/New_York'))->toBe(['vpn' => false, 'real' => null])
        ->and(VisitorLocation::vpn(false, 'Leaseweb USA, Inc.', 'US', 'America/New_York')['vpn'])->toBeTrue()
        ->and(VisitorLocation::vpn(true, 'Cyber Internet Services', 'PK', 'Asia/Karachi')['vpn'])->toBeTrue()
        ->and(VisitorLocation::vpn(false, 'Cyber Internet Services', 'PK', 'Asia/Karachi')['vpn'])->toBeFalse()
        ->and(VisitorLocation::vpn(false, null, 'PK', 'Not/AZone')['vpn'])->toBeFalse();
});

it('keeps the computer’s time zone a browser reports, and shows "VPN detected" on Live visitors', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '162.210.194.10'])
        ->call('POST', '/chat/api/ping', [], [], [], ['HTTP_ORIGIN' => 'https://ukpublishinghouse.co.uk', 'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/130'],
            json_encode(['key' => $this->widget->widget_key, 'vid' => (string) Str::uuid(), 'url' => 'https://ukpublishinghouse.co.uk/', 'tz' => 'Asia/Karachi']))
        ->assertOk();

    $session = ChatSession::query()->sole();
    expect($session->browser_tz)->toBe('Asia/Karachi');

    $session->forceFill(['country' => 'US', 'city' => 'Ashburn', 'isp' => 'Leaseweb USA, Inc.', 'is_proxy' => false])->save();

    $this->actingAs(admin())->get('/b/ukph/live-chat/visitors')->assertOk()->assertSee('VPN detected');
});
